SCRIPT = ./tests/prevTest.php
SYMFONY_DEMO_DIR = examples/06-symfony-demo
run82:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.2-cli php $(SCRIPT)
run83:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.3-cli php $(SCRIPT)
run84:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.4-cli php $(SCRIPT)
run_all:
	@make run82 && make run83 && make run84
run:
	@docker build -t recombinator-dev .
	@docker run -it --rm -v "$$PWD":/app -p 80:80 -w /app recombinator-dev sh -c "composer i && frankenphp run --config /etc/frankenphp/Caddyfile"

update-demo:
	@echo "Downloading symfony/demo..."
	@git clone --depth=1 https://github.com/symfony/demo /tmp/symfony-demo-update
	@rm -rf $(SYMFONY_DEMO_DIR)
	@mv /tmp/symfony-demo-update $(SYMFONY_DEMO_DIR)
	@echo "APP_ENV=prod" > $(SYMFONY_DEMO_DIR)/.env.local
	@echo "Installing dependencies..."
	@cd $(SYMFONY_DEMO_DIR) && composer install --no-dev --optimize-autoloader --no-scripts
	@echo "Done."
