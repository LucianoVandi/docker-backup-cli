.PHONY: help build dev install test quality clean

help: ## Mostra questo messaggio di aiuto
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Costruisce l'immagine Docker
	docker-compose build

dev: ## Avvia l'ambiente di sviluppo
	docker-compose up -d
	docker-compose exec docker-backup-dev bash

install: ## Installa le dipendenze Composer
	docker-compose exec docker-backup-dev composer install

test: ## Esegue i test PHPUnit
	docker-compose exec docker-backup-dev composer test

quality: ## Esegue controlli di qualità del codice
	docker-compose exec docker-backup-dev composer quality

clean: ## Pulisce i container e i volumi
	docker-compose down

# Comandi CLI
console: ## Avvia la console CLI
	docker-compose exec docker-backup-dev php bin/console

backup-volumes: ## Esempio backup volumi
	docker-compose exec docker-backup-dev php bin/console docker:backup:volumes

# Build commands
build-phar: ## Crea il file .phar
	docker-compose exec docker-backup-dev box compile

build-standalone: ## Crea eseguibili standalone
	docker-compose exec docker-backup-dev /usr/local/bin/build.sh

# Sviluppo
shell: ## Accedi alla shell del container
	docker-compose exec docker-backup-dev bash