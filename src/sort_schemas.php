<?php
// Topological sort to build schema packages in dependency order.
class Graph {
    public $adjacency_list = array();

    public function add_edge($source, $destination) {
        if (!array_key_exists($source, $this->adjacency_list)) {
            $this->adjacency_list[$source] = array();
        }
        array_push($this->adjacency_list[$source], $destination);
    }

    public function topological_sort() {
        $visited = array();
        $stack = new SplStack();

        foreach ($this->adjacency_list as $node => $neighbors) {
            if (!isset($visited[$node])) {
                $this->dfs_topological_sort($node, $visited, $stack);
            }
        }

        $result = array();
        while (!$stack->isEmpty()) {
            $result[] = $stack->pop();
        }
        unset($result[0]); // remove root schema from output
        $result = array_values($result);
        $result = array_reverse($result);

        return $result;
    }

    private function dfs_topological_sort($node, &$visited, &$stack) {
        $visited[$node] = true;
        if (isset($this->adjacency_list[$node])) {
            foreach ($this->adjacency_list[$node] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $this->dfs_topological_sort($neighbor, $visited, $stack);
                }
            }
        }
        $stack->push($node);
    }
}

// package_roots(): the ordered list of directories that may hold schema
// packages (each a <name>-<GUID>/ subdir). Local first (read + write --
// "ema schema" writes only there), then ema's own pkg/ (the standalone-dev
// fallback), then every installed Composer package that ships a pkg/
// subdirectory (read-only, discovered via vendor/composer/installed.php).
function package_roots() {
    static $roots = null;
    if ($roots !== null) {
        return $roots;
    }
    $roots = array();

    // 1. The consumer's own schema packages (read + write).
    if (is_dir('pkg')) {
        $roots[] = realpath('pkg');
    }

    // 2. ema's own pkg/ -- the standalone-dev fallback. Also reached via
    //    installed.php when ema is Composer-installed, so this is a harmless
    //    duplicate there and essential when there is no vendor/.
    $own = dirname(__DIR__) . '/pkg';
    if (is_dir($own)) {
        $roots[] = realpath($own);
    }

    // 3. Every installed Composer package that ships a pkg/ subdirectory.
    if (is_file('vendor/composer/installed.php')) {
        $data = require 'vendor/composer/installed.php';
        foreach ($data['versions'] ?? array() as $version) {
            $install_path = $version['install_path'] ?? null;
            if ($install_path && is_dir($install_path . '/pkg')) {
                $roots[] = realpath($install_path . '/pkg');
            }
        }
    }

    $roots = array_values(array_unique($roots));
    return $roots;
}

// find_package(): locate a schema package dir by <name>-<GUID> across all
// roots, or return null when absent. GUIDs keep cross-root matches unambiguous.
function find_package($schema) {
    foreach (package_roots() as $root) {
        $dir = $root . '/' . $schema;
        if (is_dir($dir)) {
            return $dir;
        }
    }
    return null;
}

function extract_dependencies($schema) {
    $dir = find_package($schema);
    if ($dir === null) {
        fwrite(STDERR, "Error: schema package '$schema' not found\n");
        exit(1);
    }
    require_once $dir . '/default.php';
    return $dependencies;
}

function build_dependency_graph($root_schema) {
    $graph = new Graph();
    $visited = array();
    $stack = new SplStack();
    $stack->push($root_schema);

    while (!$stack->isEmpty()) {
        $current_schema = $stack->pop();
        if (isset($visited[$current_schema])) {
            continue;
        }
        $visited[$current_schema] = true;
        $dependencies = extract_dependencies($current_schema);
        foreach ($dependencies as $dependency) {
            $graph->add_edge($current_schema, $dependency);
            if (find_package($dependency) !== null) {
                $stack->push($dependency);
            }
        }
    }

    return $graph;
}

if (!count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))) {
    if ($argc < 2) {
        fwrite(STDERR, "Usage: php sort_schemas.php <root-pkg>\n");
        exit(1);
    }
    $root_schema = $argv[1];
    $graph = build_dependency_graph($root_schema);

    echo "Dependency Graph:\n";
    foreach ($graph->adjacency_list as $node => $deps) {
        echo "$node -> " . implode(", ", $deps) . "\n";
    }

    echo "\nTopological Ordering:\n";
    echo implode(", ", $graph->topological_sort()) . "\n";
}
?>
