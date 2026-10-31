.PHONY: help
.DEFAULT_GOAL := help

help:
	@echo "Usage: make [target]"
	@echo ""
	@echo "Targets:"
	@echo "  run             Build Docker image and start FrankenPHP server"
	@echo "  update-examples Clone/refresh all project-type examples defined in examples/meta.json"
	@echo
	@echo "Стандартные цели ~/projects:"
	@grep -hE '^[a-zA-Z0-9_-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

run:
	@docker build -t recombinator-dev .
	@docker run -it --rm -v "$$PWD":/app -p 127.0.0.1:85:80 -w /app recombinator-dev sh -c "composer i && frankenphp run --config /etc/frankenphp/Caddyfile"

update-examples:
	@php bin/update-examples.php

# --- стандартные цели ~/projects (make help) ---

.PHONY: install
install: ## поставить зависимости
	composer install

.PHONY: dev
dev: ## запустить для разработки (веб-интерфейс из ./public на http://localhost:8085)
	composer serve

.PHONY: build
build: ## сборка
	@echo "сборки нет"

.PHONY: audit
audit: ## уязвимости в зависимостях
	composer audit

.PHONY: outdated
outdated: ## устаревшие зависимости
	composer outdated --direct
