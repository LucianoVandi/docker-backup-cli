# Docker Backup & Restore CLI Tool

Utility da linea di comando per il backup e restore di risorse Docker (volumi e immagini).

## Requisiti

### Docker
- **Versione minima supportata**: Docker 19.03+
- **Versione consigliata**: Docker 23.0+ (per performance ottimali)
- **Compatibilità**: Funziona automaticamente con tutte le versioni tramite fallback

### Sistema
- PHP 8.4+
- Accesso al socket Docker (`/var/run/docker.sock`)

## Installazione Sviluppo

```bash
# Clone del repository
git clone <repository-url>
cd docker-backup-cli

# Build ambiente di sviluppo
make build

# Avvio container di sviluppo
make dev

# Installazione dipendenze
make install
```

## Utilizzo

### Backup Volumi

```bash
# Lista volumi disponibili
php bin/console docker:backup:volumes --list

# Backup di volumi specifici
php bin/console docker:backup:volumes volume1 volume2

# Backup con directory personalizzata
php bin/console docker:backup:volumes volume1 --output-dir ./my-backups

# Help completo
php bin/console docker:backup:volumes --help
```

### Note sulla Compatibilità

L'applicazione rileva automaticamente la versione Docker e si adatta:

- **Docker 23.0+**: Usa formato JSON nativo per performance ottimali
- **Docker 19.03-22.x**: Usa metodo compatibile con `docker inspect`
- **Fallback**: In caso di errori, utilizza sempre metodi standard

## Sviluppo

### Struttura Progetto

```
├── bin/console              # Entry point CLI
├── config/                  # Configurazione Symfony
├── src/
│   ├── Command/            # Comandi CLI
│   ├── Service/            # Logica business
│   ├── ValueObject/        # Oggetti valore immutabili
│   └── Exception/          # Eccezioni personalizzate
├── docker-compose.yml      # Ambiente sviluppo
└── Dockerfile              # Container con Docker aggiornato
```

### Comandi Make

```bash
make help          # Mostra tutti i comandi disponibili
make build         # Build container
make dev           # Avvia ambiente sviluppo
make install       # Installa dipendenze
make test          # Esegui test
make quality       # Controlli qualità codice
make build-phar    # Crea file .phar
make build-standalone  # Crea eseguibili multipiattaforma
```

## Build Produzione

```bash
# Crea .phar
make build-phar

# Crea eseguibili standalone per tutte le piattaforme
make build-standalone
```

Gli eseguibili vengono generati in `./build/`:
- `docker-backup-linux-x64`
- `docker-backup-windows-x64.exe`
- `docker-backup-macos-x64`
- `docker-backup-macos-arm64`