<?php
require __DIR__ . "/ale_import_cats.php";

class AleImportCli
{
    /* DELETE FROM `wp_terms` WHERE term_id >27 */
    private $catsImport;

    public function __construct(){
        $this->catsImport = new AleImportCats(50);
    } 


    public function deleteCats()
    {
        $this->catsImport->deleteCats();
    }


    public function import()
    {
        $path = __DIR__ . '/product_info_uk.json' ;
        $jsonString = json_decode(file_get_contents($path), true);
        $cats = $this->catsImport->insertCats($jsonString);

        /* WP_CLI::line( 'Hello World!' ); */
    }
}
