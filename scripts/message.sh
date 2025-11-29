#!/bin/bash

# Display a formatted message with colored background
# Usage: ./scripts/message.sh --level <level> "<message>"
# Levels: success, error, warning, info

LEVEL="info"
MESSAGE=""

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --level)
            LEVEL="$2"
            shift 2
            ;;
        *)
            MESSAGE="$1"
            shift
            ;;
    esac
done

# Define colors based on level (using bold for better contrast)
case $LEVEL in
    success)
        COLOR_CODE="1;42;30"  # Bold, Green background, Black text
        ICON="✓ ✓ ✓"
        ;;
    error)
        COLOR_CODE="1;41;30"  # Bold, Red background, Black text
        ICON="✗ ✗ ✗"
        ;;
    warning)
        COLOR_CODE="1;43;30"  # Bold, Yellow background, Black text
        ICON="⚠ ⚠ ⚠"
        ;;
    info)
        COLOR_CODE="1;44;97"  # Bold, Blue background, Bright white text
        ICON="ℹ ℹ ℹ"
        ;;
    *)
        COLOR_CODE="1;47;30"  # Bold, Light gray background, Black text
        ICON="•••"
        ;;
esac

# Calculate width (80 chars)
WIDTH=80

# Format the message without icons first
MESSAGE_TEXT="$MESSAGE"

# Calculate padding for centering (use character count)
MSG_LEN=$(echo -n "$MESSAGE_TEXT" | wc -m | tr -d ' ')
ICON_LEN=$(echo -n "$ICON  " | wc -m | tr -d ' ')
TOTAL_MSG_LEN=$((MSG_LEN + ICON_LEN + ICON_LEN))
PADDING=$(( (WIDTH - TOTAL_MSG_LEN) / 2 ))
RIGHT_PADDING=$(( WIDTH - TOTAL_MSG_LEN - PADDING ))

# Build the line piece by piece
printf -v EMPTY_LINE "%${WIDTH}s" ""
printf -v LEFT_SPACES "%${PADDING}s" ""
printf -v RIGHT_SPACES "%${RIGHT_PADDING}s" ""

# Print the message box
echo ""
printf "\033[${COLOR_CODE}m%s\033[0m\n" "$EMPTY_LINE"
printf "\033[${COLOR_CODE}m%s\033[0m\n" "$EMPTY_LINE"
printf "\033[${COLOR_CODE}m%s%s  %s  %s%s\033[0m\n" "$LEFT_SPACES" "$ICON" "$MESSAGE_TEXT" "$ICON" "$RIGHT_SPACES"
printf "\033[${COLOR_CODE}m%s\033[0m\n" "$EMPTY_LINE"
printf "\033[${COLOR_CODE}m%s\033[0m\n" "$EMPTY_LINE"
echo ""
