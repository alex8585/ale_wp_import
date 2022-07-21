<?php
require __DIR__ . "/ale_import_cats.php";
require __DIR__ . "/ale_import_products.php";
require __DIR__ . "/ale_import_images.php";

class AleImportCli
{
//    DELETE FROM `wp_terms` WHERE term_id >27 ; DELETE FROM `wp_term_taxonomy` WHERE term_id >27; DELETE  FROM `wp_woocommerce_attribute_taxonomies` WHERE attribute_id > 3 
    private $catsImport;
    private $productsImport;
    private $imagesImport;
    private $feedFilePath;

    public function __construct(){
        $this->feedFilePath = __DIR__ . '/product_info_uk.json' ;
        $this->catsImport = new AleImportCats(50);
        $this->productsImport = new AleImportProducts(30);
        $this->imagesImport = new AleImportImages(20);
    } 


    public function importImages()
    {
        $jsonString = json_decode(file_get_contents($this->feedFilePath), true);
        $this->imagesImport->importImages($jsonString);
    }

    public function deleteCats()
    {
        $this->catsImport->deleteCats();
    }

    public function deleteProducts()
    {
        $this->productsImport->deleteProducts();
    }

    public function importCats() {
        $jsonString = json_decode(file_get_contents($this->feedFilePath), true);
        $this->catsImport->insertCats($jsonString);
    }

    public function importProducts()
    {
        $jsonString = json_decode(file_get_contents($this->feedFilePath), true);
        $this->productsImport->insertProducts($jsonString);

    }
}
