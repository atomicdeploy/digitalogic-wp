# Contributing to Digitalogic WordPress Plugin

Thank you for your interest in contributing to the Digitalogic WordPress plugin! This document provides guidelines and instructions for contributing.

## Code of Conduct

- Be respectful and inclusive
- Focus on constructive feedback
- Help others learn and grow
- Maintain professionalism

## Getting Started

### Prerequisites

- PHP 8.0 or higher
- Composer
- WordPress development environment
- WooCommerce plugin
- Git

### Development Setup

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/YOUR-USERNAME/digitalogic-wp.git
   cd digitalogic-wp
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Create a branch for your feature:
   ```bash
   git checkout -b feature/your-feature-name
   ```

## Development Workflow

### Coding Standards

We follow WordPress Coding Standards:

- Use WordPress coding style for PHP
- Use 4 spaces for indentation (no tabs)
- Follow PSR-4 autoloading standards
- Add proper DocBlocks to all functions and classes

### Running Code Quality Checks

```bash
# Check code style
composer phpcs

# Fix code style automatically
composer phpcbf

# Check PHP syntax
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;
```

### Testing

```bash
# Run unit tests
composer test

# Or with PHPUnit directly
vendor/bin/phpunit
```

### Commit Messages

Follow conventional commit format:

- `feat: Add new feature`
- `fix: Fix bug in product manager`
- `docs: Update API documentation`
- `style: Format code`
- `refactor: Refactor pricing logic`
- `test: Add tests for import/export`
- `chore: Update dependencies`

Example:
```bash
git commit -m "feat: Add Excel export support via PhpSpreadsheet"
```

## What to Contribute

### Bug Fixes

1. Check if the bug is already reported in Issues
2. Create a new issue if it doesn't exist
3. Fork and create a branch
4. Fix the bug with tests
5. Submit a pull request

### New Features

1. Open an issue to discuss the feature first
2. Wait for approval from maintainers
3. Implement the feature
4. Add tests and documentation
5. Submit a pull request

### Documentation

- API documentation improvements
- Code examples
- Installation guides
- Translation contributions

### Areas for Contribution

- **UI/UX Improvements**: Better admin interface, accessibility
- **Performance**: Query optimization, caching
- **Features**: 
  - Excel import/export with PhpSpreadsheet
  - Additional currency support
  - Advanced pricing rules
  - Integration with accounting software
  - Mobile app API
- **Translations**: Persian, Arabic, and other languages
- **Testing**: Unit tests, integration tests

## Pull Request Process

1. **Update Documentation**: Ensure README and relevant docs are updated
2. **Add Tests**: Include tests for new features
3. **Run Quality Checks**: Ensure all checks pass
4. **Update Changelog**: Add entry to CHANGELOG.md
5. **Small PRs**: Keep changes focused and small
6. **Description**: Provide clear description of changes

### PR Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
How to test these changes

## Checklist
- [ ] Code follows WordPress coding standards
- [ ] Tests added/updated
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
```

## Code Review Process

1. Maintainers will review your PR
2. Address any feedback
3. Once approved, it will be merged
4. Your contribution will be credited

## Project Structure

```
digitalogic-wp/
├── assets/               # CSS and JavaScript files
│   ├── css/
│   └── js/
├── includes/            # PHP classes
│   ├── admin/          # Admin interface
│   ├── api/            # REST API and webhooks
│   ├── cli/            # WP-CLI commands
│   └── *.php           # Core classes
├── languages/          # Translation files
├── docs/              # Documentation
├── tests/             # Unit tests
└── digitalogic.php    # Main plugin file
```

## Adding New Features

### Example: Adding a New REST Endpoint

1. Add method to `includes/api/class-rest-api.php`:
   ```php
   public function get_stats(WP_REST_Request $request) {
       // Implementation
   }
   ```

2. Register route in `register_routes()`:
   ```php
   register_rest_route('digitalogic/v1', '/stats', array(
       'methods' => 'GET',
       'callback' => array($this, 'get_stats'),
       'permission_callback' => array($this, 'check_permission')
   ));
   ```

3. Add tests in `tests/test-rest-api.php`

4. Update documentation in `docs/API.md`

### Example: Adding a WP-CLI Command

1. Add method to `includes/cli/class-cli-commands.php`:
   ```php
   public function stats($args, $assoc_args) {
       // Implementation
   }
   ```

2. Register command:
   ```php
   WP_CLI::add_command('digitalogic stats', array('Digitalogic_CLI_Commands', 'stats'));
   ```

3. Add documentation with PHPDoc

## Security

- **Never commit secrets** (API keys, passwords)
- **Validate all input** (sanitize, escape)
- **Use nonces** for form submissions
- **Check capabilities** before operations
- **Use prepared statements** for database queries
- **Report vulnerabilities** privately to maintainers

## Questions?

- Open an issue for questions
- Check existing documentation
- Review closed issues and PRs

## License

By contributing, you agree that your contributions will be licensed under the GPL v2 or later license.

## Recognition

All contributors will be acknowledged in:
- CONTRIBUTORS.md file
- Release notes
- Plugin credits

Thank you for contributing to Digitalogic! 🚀
