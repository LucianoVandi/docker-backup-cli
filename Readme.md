# Docker Backup & Restore CLI Tool

A command-line utility for backing up and restoring Docker resources (volumes and images).

## Quick Start

### Download Prebuilt Binaries

Download the latest release from [GitHub Releases](https://github.com/LucianoVandi/docker-backup-cli/releases):

```bash
# Linux x64
wget https://github.com/LucianoVandi/docker-backup-cli/docker-backup-cli/releases/latest/download/docker-backup-linux-x64-v1.0.0
chmod +x docker-backup-linux-x64-v1.0.0
./docker-backup-linux-x64-v1.0.0 --help

# macOS x64
wget https://github.com/LucianoVandi/docker-backup-cli/docker-backup-cli/releases/latest/download/docker-backup-macos-x64-v1.0.0
chmod +x docker-backup-macos-x64-v1.0.0
./docker-backup-macos-x64-v1.0.0 --help

# Windows x64
# Download docker-backup-windows-x64-v1.0.0.exe and run directly
```

### Alternative: Using .phar

```bash
# Download .phar file
wget https://github.com/LucianoVandi/docker-backup-cli/docker-backup-cli/releases/latest/download/docker-backup-v1.0.0.phar

# Run with PHP
php docker-backup-v1.0.0.phar --help
```

## Requirements

### Docker
- **Minimum supported version**: Docker 23.0+ (for optimal performance)ms

### System Requirements
- **For standalone executables**: Docker installed and running
- **For .phar file**: PHP 8.1+ + Docker CLI access
- Access to Docker socket (`/var/run/docker.sock`)

## Usage

### Volume Operations

```bash
# List available volumes
./docker-backup backup:volumes --list

# Backup specific volumes
./docker-backup backup:volumes volume1 volume2

# Backup with custom output directory
./docker-backup backup:volumes volume1 --output-dir ./my-backups

# Create uncompressed backups
./docker-backup backup:volumes volume1 --no-compression

# Restore volumes
./docker-backup restore:volumes volume1.tar.gz

# Restore with overwrite existing volumes
./docker-backup restore:volumes volume1.tar.gz --overwrite

# List available volume backups
./docker-backup restore:volumes --list
```

### Image Operations

```bash
# List available images
./docker-backup backup:images --list

# Backup specific images
./docker-backup backup:images nginx:latest mysql:8.0

# Backup image by ID
./docker-backup backup:images sha256:1234567890abcdef

# Backup with custom output directory
./docker-backup backup:images nginx:latest --output-dir ./my-backups

# Create uncompressed backups
./docker-backup backup:images nginx:latest --no-compression

# Restore images
./docker-backup restore:images nginx_latest.tar.gz

# Restore with overwrite existing images
./docker-backup restore:images nginx_latest.tar.gz --overwrite

# List available image backups
./docker-backup restore:images --list
```

### Global Options

```bash
# Get help for any command
./docker-backup backup:volumes --help
./docker-backup restore:images --help

# Show version information
./docker-backup --version
```

## Development Setup

### Prerequisites

- Docker (for development environment)
- Make (optional, for convenience commands)

### Getting Started

```bash
# Clone the repository
git clone https://github.com/LucianoVandi/docker-backup-cli
cd docker-backup-cli

# Build development environment
make build

# Start development container
make dev

# Install dependencies
make install
```

### Development Commands

```bash
make help               # Show all available commands
make build              # Build development container
make dev                # Start development environment
make install            # Install dependencies
make test               # Run test suite
make quality            # Run code quality checks
make build-phar         # Create .phar file
make build-standalone   # Create standalone executables
```

## Architecture

### Project Structure

```
├── bin/console              # CLI entry point
├── src/
│   ├── Command/            # CLI commands
│   ├── Service/            # Business logic services
│   ├── ValueObject/        # Immutable value objects
│   ├── Exception/          # Custom exceptions
│   └── Trait/              # Shared utilities
├── tests/                  # Test suite
├── docker-compose.yml      # Development environment
├── Dockerfile              # Development container
├── box.json               # .phar configuration
└── .github/workflows/     # CI/CD pipeline
```

### Key Components

- **Commands**: CLI interface using Symfony Console
- **Services**: Business logic for backup/restore operations
- **Docker Integration**: Native Docker API integration
- **File System**: Efficient handling of large archives
- **Cross-Platform**: Standalone executables for all major platforms

### Supported Archive Formats

- **Compressed**: `.tar.gz` (default, space-efficient)
- **Uncompressed**: `.tar` (faster for large files)
- **Auto-detection**: Handles both formats transparently

## Building from Source

### Development Build

```bash
# Inside development container
composer install
php bin/console --help
```

### Production Build

```bash
# Create .phar file
make build-phar

# Create standalone executables for all platforms
make build-standalone
```

Generated files:
- `docker-backup.phar` - Portable PHP archive
- `build/docker-backup-linux-x64` - Linux executable
- `build/docker-backup-windows-x64.exe` - Windows executable
- `build/docker-backup-macos-x64` - macOS Intel executable
- `build/docker-backup-macos-arm64` - macOS Apple Silicon executable

### Release Process

Releases are automatically created when you push a version tag:

```bash
# Create and push a version tag
git tag v1.0.0
git push origin v1.0.0

# GitHub Actions will automatically:
# 1. Build all executables
# 2. Create a GitHub release
# 3. Upload all assets
```

## Testing

```bash
# Run full test suite
make test

# Run specific test types
vendor/bin/phpunit tests/Unit/
vendor/bin/phpunit tests/Integration/
```

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feat/new-feature`
3. Make your changes
4. Run tests: `make test`
5. Run quality checks: `make quality`
6. Commit your changes: `git commit -m 'Add new feature'`
7. Push to the branch: `git push origin feat/new-feature`
8. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

- **Issues**: [GitHub Issues](https://github.com/LucianoVandi/docker-backup-cli/docker-backup-cli/issues)
- **Documentation**: This README and command help (`--help`)

## Acknowledgments

- Built with [Symfony Console](https://symfony.com/doc/current/console.html)
- Packaged with [Box](https://github.com/box-project/box)
- Standalone executables with [PHPacker](https://github.com/phpacker/phpacker)