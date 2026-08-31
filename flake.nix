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
        devShells.default = pkgs.mkShell {
          buildInputs = commonPackages ++ [
            mariadbPkg
            phpComposer
          ];
          shellHook = ''
            . ./bin/dev/shell-enter.sh ema

            # Customize the prompt (PS1)
            # Define ANSI color codes for readability
            CYAN='\033[0;36m'
            PURPLE='\033[0;35m'
            GREEN='\033[0;32m'
            NC='\033[0m' # No Color
            export PS1="\[$CYAN\] \u@\h:\[$GREEN\]\w\[$NC\]\$ "
          '';
        };
      }
    );
}
