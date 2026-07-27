.PHONY: test phpstan cs check fix golden help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

test: ## Run tests
	vendor/bin/phpunit

phpstan: ## Run phpstan
	vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=1G

cs: ## Run php-cs-fixer (dry-run) and phpcs
	vendor/bin/php-cs-fixer fix --dry-run --diff
	vendor/bin/phpcs

golden: ## Rewrite the golden corpus snapshots from the current output (review the diff!)
	UPDATE_GOLDEN_CORPUS=1 vendor/bin/phpunit --filter GoldenCorpus

check: test phpstan cs ## Run all checks (tests, phpstan, cs)

fix: ## Run auto-fixes (php-cs-fixer and phpcbf)
	vendor/bin/php-cs-fixer fix
	vendor/bin/phpcbf
