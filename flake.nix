{
  description = "Package manager for MariaDB";

  inputs = {
    nixpkgs.url = "github:nixos/nixpkgs/nixpkgs-unstable";
    utils.url = "github:numtide/flake-utils";
  };

  outputs = { self, nixpkgs, utils }:
    utils.lib.eachDefaultSystem (system:
      let
        pkgs = import nixpkgs { inherit system; };

        phpPkg = pkgs.php84.withExtensions ({ all, enabled }:
          enabled ++ [
            all.mysqli
            all.pdo_mysql
          ]
        );
        phpComposer = pkgs.php84Packages.composer.override {
          php = phpPkg;
        };
        jqPkg = pkgs.jq;
        mariadbPkg = pkgs.mariadb_118;

        commonPackages = [
          jqPkg
          phpPkg
        ];
      in
      {
        packages.default = pkgs.stdenv.mkDerivation {
          pname = "ema";
          version = "1.0.0";
          src = ./.;
          nativeBuildInputs = [ pkgs.makeWrapper ];
          installPhase = ''
            mkdir -p $out/bin $out/share/ema
            cp src/sort_schemas.php $out/share/ema/sort_schemas.php
            cp ema $out/bin/.ema-unwrapped
            chmod +x $out/bin/.ema-unwrapped
            makeWrapper $out/bin/.ema-unwrapped $out/bin/ema \
              --set EMA_LIB "$out/share/ema" \
              --suffix PATH : ${phpPkg}/bin
          '';
        };

        devShells.default = pkgs.mkShell {
          buildInputs = commonPackages ++ [
            mariadbPkg
            phpComposer
          ];
          shellHook = ''
            export EMA_REPO_PATH="$PWD"
            export EMA_LIB="$EMA_REPO_PATH/src"

            export MYSQL_BASE_DIR="$EMA_REPO_PATH/var/mariadb"
            export MYSQL_DATA_DIR="$MYSQL_BASE_DIR/data"
            export MYSQL_UNIX_PORT="$MYSQL_BASE_DIR/mysql.sock"
            export MYSQL_PID_FILE="$MYSQL_BASE_DIR/mysql.pid"

            if [ -d "$MYSQL_DATA_DIR" ]; then
              echo "Starting isolated MariaDB server..."
              mysqld --datadir="$MYSQL_DATA_DIR" \
                     --pid-file="$MYSQL_PID_FILE" \
                     --socket="$MYSQL_UNIX_PORT" \
                     --skip-networking > /dev/null 2>&1 &

              MARIADB_PID=$!
              trap "echo 'Stopping local MariaDB server...'; kill $MARIADB_PID; wait $MARIADB_PID 2>/dev/null" EXIT
            fi

            export EMA_TARGET="local"

            CYAN='\033[0;36m'
            GREEN='\033[0;32m'
            NC='\033[0m'
            export PS1="\[$CYAN\] \u@\h:\[$GREEN\]\w\[$NC\]\$ "
          '';
        };
      }
    );
}
