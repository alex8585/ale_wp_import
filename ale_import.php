<?php
require __DIR__ . "/ale_import_cats.php";
require __DIR__ . "/ale_import_products.php";

class AleImportCli
{
    /* DELETE FROM `wp_terms` WHERE term_id >27 */
    private $catsImport;
    private $productsImport;

    public function __construct(){
        $this->catsImport = new AleImportCats(50);
        $this->productsImport = new AleImportProducts(30);
    } 


    public function deleteCats()
    {
        $this->catsImport->deleteCats();
    }


    public function importCats() {
        $path = __DIR__ . '/product_info_uk.json' ;
        $jsonString = json_decode(file_get_contents($path), true);
        $this->catsImport->insertCats($jsonString);
    }

    public function importProducts()
    {
        $path = __DIR__ . '/product_info_uk.json' ;
        $jsonString = json_decode(file_get_contents($path), true);

        $this->productsImport->insertProducts($jsonString);

    }
}
