#!/bin/bash

set -e

echo "🚀 Avvio processo di build..."

# Crea directory di build
mkdir -p build

# Step 1: Installa/aggiorna dipendenze
echo "📦 Installazione dipendenze..."
composer install --no-dev --optimize-autoloader

# Step 2: Crea il file .phar con Box
echo "📦 Creazione file .phar con Box..."
box compile

echo "✅ File .phar creato: docker-backup.phar"

# Step 3: Verifica che PHPacker sia disponibile
if command -v phpacker >/dev/null 2>&1; then
    echo "🔧 Generazione eseguibili standalone..."

    # Opzione 1: Build per tutte le piattaforme
    echo "  → Building for all platforms..."
    phpacker build all --src=docker-backup.phar --dest=build/ || echo "⚠️  All platforms build failed"

    # Se il build "all" fallisce, proviamo uno per uno
    if [ $? -ne 0 ]; then
        echo "  → Trying individual platform builds..."
        phpacker build all --src=docker-backup.phar --dest=build/
    fi

    echo "✅ Eseguibili standalone completati!"
else
    echo "⚠️  PHPacker non disponibile, skip eseguibili standalone"
    echo "💡 Puoi usare il file .phar con: php docker-backup.phar"
fi

echo "✅ Build completato!"
echo "📁 File generati:"
ls -la docker-backup.phar build/ 2>/dev/null || ls -la docker-backup.phar