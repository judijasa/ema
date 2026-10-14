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

        # readline: fixes arrow keys in nix shell on NixOS (without it, arrows produce escape sequences)
        # ncurses: together with readline, it fixes arrow keys in nix shell + tmux on NixOS
        bashPkg = pkgs.bashInteractive;  # Includes readline + ncurses
        jqPkg = pkgs.jq;
        mariadbPkg = pkgs.mariadb_118;
        phpPkg = pkgs.php84.withExtensions ({ all, enabled }:
          enabled ++ [
            all.mysqli
            all.pdo_mysql
          ]
        );
        phpComposer = pkgs.php84Packages.composer.override {
          php = phpPkg;
        };

        commonPackages = [
          bashPkg
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
            # Set SHELL to bashInteractive so tmux uses bash with readline support
            export SHELL="${pkgs.bashInteractive}/bin/bash"

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
