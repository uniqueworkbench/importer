#!/bin/bash

# Read current version from composer.json
current_version=$(grep '"version"' composer.json | sed 's/.*"version": "\(.*\)".*/\1/')

if [[ ! "$current_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: version '$current_version' in composer.json is not in major.minor.patch form."
    exit 1
fi

# Work out each bump, keeping each segment's zero padding (1.00.09 -> 1.00.10 / 1.01.00 / 2.00.00)
IFS='.' read -r major minor patch <<< "$current_version"
pad() { printf "%0${#2}d" "$1"; }   # pad <value> <segment to match width of>
patch_version="$major.$minor.$(pad $((10#$patch + 1)) "$patch")"
minor_version="$major.$(pad $((10#$minor + 1)) "$minor").$(pad 0 "$patch")"
major_version="$(pad $((10#$major + 1)) "$major").$(pad 0 "$minor").$(pad 0 "$patch")"

echo "This will deploy all changes into GIT 'develop'"
echo "-----------------------------------------------"
echo "Current version in composer.json: $current_version"
echo "  1) patch -> $patch_version (default)"
echo "  2) minor -> $minor_version"
echo "  3) major -> $major_version"
read -p "Select version bump [1]: " bump

case "${bump:-1}" in
    1|p|patch) new_version="$patch_version" ;;
    2|m|minor) new_version="$minor_version" ;;
    3|M|major) new_version="$major_version" ;;
    *) echo "Error: '$bump' is not a valid choice."; exit 1 ;;
esac

read -p "Deploy version $new_version? (y/n): " confirm

if [[ "$confirm" == "y" || "$confirm" == "Y" ]]; then
    # Write the new version into composer.json (first "version" line only)
    sed -i "0,/\"version\": \"[^\"]*\"/s//\"version\": \"$new_version\"/" composer.json

    git add .
    git commit -m "v$new_version"
    git push -u origin develop

    # Apps require "*", which resolves to the latest vX.Y.Z tag — without the tag
    # the release never reaches them (each app's push.sh checks GitHub's tags)
    git tag -a "v$new_version" -m "v$new_version"
    git push origin "v$new_version"

    echo "Complete. Released v$new_version — each app picks it up on its next ./push.sh."
else
    echo "Deployment cancelled."
fi