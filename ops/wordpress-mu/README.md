# Retired WordPress deployment adapters

Digitalogic no longer ships separate MU deployment adapters. Their canonical
implementations live in the `digitalogic-wp` plugin and are loaded by its main
bootstrap. Production migration must preserve the former files only in a
server-side rollback archive, outside `wp-content/mu-plugins`, and remove them
from the active plugin surface after parity and live acceptance are verified.
