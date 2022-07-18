# Ale wp import 

## Description:
This code adds import cli commands to woocomerce. That alows you to import categories and products from products feed  to woocomerce shop.

## Installation:
To activate this import code you have to add following hook to your current woocommerce theme in functions.php file
and paste this repository code folder in the root of this theme as well.

```    
require __DIR__ . '/ale_wp_import/ale_import.php';
function wds_cli_register_commands()
{
    WP_CLI::add_command('ale_import', 'AleImportCli');
}

add_action('cli_init', 'wds_cli_register_commands');
```
