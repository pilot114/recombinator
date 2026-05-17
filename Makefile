.PHONY: help
.DEFAULT_GOAL := help

SCRIPT = ./tests/prevTest.php
SYMFONY_DEMO_DIR = examples/06-symfony-demo

help:
	@echo "Usage: make [target]"
	@echo ""
	@echo "Targets:"
	@echo "  run82        Run tests in Docker (PHP 8.2)"
	@echo "  run83        Run tests in Docker (PHP 8.3)"
	@echo "  run84        Run tests in Docker (PHP 8.4)"
	@echo "  run          Build Docker image and start FrankenPHP server"
	@echo "  update-demo  Download latest symfony/demo into examples/06-symfony-demo"

run82:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.2-cli php $(SCRIPT)
run83:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.3-cli php $(SCRIPT)
run84:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.4-cli php $(SCRIPT)

run:
	@docker build -t recombinator-dev .
	@docker run -it --rm -v "$$PWD":/app -p 80:80 -w /app recombinator-dev sh -c "composer i && frankenphp run --config /etc/frankenphp/Caddyfile"

update-demo:
	@echo "Downloading symfony/demo..."
	@git clone --depth=1 https://github.com/symfony/demo /tmp/symfony-demo-update
	@rm -rf $(SYMFONY_DEMO_DIR)
	@mv /tmp/symfony-demo-update $(SYMFONY_DEMO_DIR)
	@echo "APP_ENV=prod" > $(SYMFONY_DEMO_DIR)/.env.local
	@echo "APP_SECRET=12345qwerty" >>$(SYMFONY_DEMO_DIR)/.env.local
	@echo "Installing dependencies..."
	@cd $(SYMFONY_DEMO_DIR) && composer install --no-dev --optimize-autoloader --no-scripts
	@echo "Building assets..."
	@cd $(SYMFONY_DEMO_DIR) && php bin/console importmap:install
	@cd $(SYMFONY_DEMO_DIR) && php bin/console sass:build
	@cd $(SYMFONY_DEMO_DIR) && php bin/console asset-map:compile
	@echo "Done."
