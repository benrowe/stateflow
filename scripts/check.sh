#!/bin/bash

# Run all quality checks and report final status
# Each check runs regardless of previous failures

EXIT_CODE=0

echo "Running code style checks..."
composer lint
if [ $? -ne 0 ]; then
    EXIT_CODE=1
fi

echo ""
echo "Running static analysis..."
composer quality
if [ $? -ne 0 ]; then
    EXIT_CODE=1
fi

echo ""
if php -m 2>/dev/null | grep -q pcov; then
    echo "Running tests with coverage..."
    composer test:coverage
else
    echo "Running tests (pcov not available, skipping coverage)..."
    composer test
fi
if [ $? -ne 0 ]; then
    EXIT_CODE=1
fi

echo ""
if [ $EXIT_CODE -eq 0 ]; then
    scripts/message.sh --level success "All Checks Passed Successfully"
else
    scripts/message.sh --level error "Some Checks Failed"
    exit 1
fi
