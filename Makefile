# Digital Workflow - development tasks.
# Everything runs inside Docker (compose.yaml); no local PHP/PostgreSQL needed.

DC   := docker compose
PHP  := $(DC) exec -T app
NODE := docker run --rm -u $$(id -u):$$(id -g) -v "$$PWD":/app -w /app node:22-alpine

.PHONY: help install build up down dev dev-stop migrate fresh demo seed shell psql logs test test-ldap shots lint assets audit

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

install: ## First-time setup: image, dependencies, key, database, assets
	@test -f .env || cp .env.example .env
	$(DC) build app
	$(DC) run --rm --no-deps app composer install
	$(DC) up -d
	$(PHP) php artisan key:generate
	$(PHP) php artisan migrate --seed --force
	$(NODE) sh -c "npm ci && npm run build"
	@rm -f public/hot
	@echo "\nReady at http://localhost:$${APP_PORT:-8100}  (mail: http://localhost:$${MAILPIT_UI_PORT:-8028})"
	@echo "Run 'make demo' for the synthetic organisation and the example workflows.\n"

build: ## Rebuild the PHP image
	$(DC) build app

up: ## Start the stack (compiled assets)
	@rm -f public/hot
	$(DC) up -d

down: ## Stop the stack
	$(DC) down
	@rm -f public/hot

dev: ## Start the Vite dev server (hot reload)
	$(DC) --profile dev up -d node

dev-stop: ## Stop Vite and fall back to compiled assets
	$(DC) stop node
	@rm -f public/hot

migrate: ## Run pending migrations
	$(PHP) php artisan migrate

fresh: ## Drop everything and re-seed base data
	$(PHP) php artisan migrate:fresh --seed --force

demo: ## Synthetic organisation and data, and the example workflows of every module (Computer Centre, e-Office, Registrar, Finance)
	$(PHP) php artisan db:seed --class=DemoDataSeeder --force
	$(PHP) php artisan db:seed --class=ComputerCentreWorkflowSeeder --force
	$(PHP) php artisan db:seed --class=EFileDemoSeeder --force
	$(PHP) php artisan db:seed --class=RegistrarWorkflowSeeder --force
	$(PHP) php artisan db:seed --class=RegistrarDemoSeeder --force
	$(PHP) php artisan db:seed --class=FinanceWorkflowSeeder --force
	$(PHP) php artisan db:seed --class=FinanceDemoSeeder --force
	$(PHP) php artisan db:seed --class=InstituteFormsSeeder --force

seed: ## Re-run base seeders (idempotent)
	$(PHP) php artisan db:seed --force

shell: ## Shell in the PHP container
	$(DC) exec app bash

psql: ## PostgreSQL client on the app database
	$(DC) exec postgres psql -U workflow digital_workflow

logs: ## Tail app, queue and nginx logs
	$(DC) logs -f app queue nginx

test: ## PHPUnit (separate digital_workflow_testing DB) + shared rule fixtures in Node
	$(PHP) php artisan test
	$(NODE) npm run test:rules

test-ldap: ## Integration tests against the development OpenLDAP container
	$(PHP) php artisan test --group=ldap

shots: ## Screenshots of key pages (needs: make fresh demo; Chrome on the host)
	node tests/Browser/shots.mjs http://localhost:$${APP_PORT:-8100}

lint: ## Format PHP and type-check the frontend
	$(PHP) ./vendor/bin/pint
	$(NODE) npm run type-check

assets: ## Build production frontend assets
	$(NODE) npm run build
	@rm -f public/hot

audit: ## Dependency vulnerability audit
	$(PHP) composer audit
	$(NODE) npm audit --omit=dev
