<?php
require __DIR__ . "/ale_import_attributes.php";

class AleImportProducts
{
    private $productsChunks;
    private $importAttributes;

    public function __construct($productsChunks = 20)
    {
        $this->productsChunks = $productsChunks;
        $this->importAttributes = new AleImportAttributes(30);
    }

    public function insertProducts($jsonString)
    {
        $products = $this->getProducts($jsonString);
        if (!$products) {
            return;
        }

        $products = $this->insertPosts($products);
        /* print_r($products); */
        $this->insertMeta($products);
        $this->importAttributes->insertAttributes($products);
        $this->insertCategory($products);
    }

    public function deleteProducts()
    {
        global $wpdb;
        $sql = "SELECT post_id  FROM " . $wpdb->postmeta . " WHERE meta_key='foreign_id' ";
        $postsIds = $wpdb->get_col($sql) ;

        /* print_r(count($postsIds)); */
        if (!$postsIds) {
            return;
        }


        $sql = "DELETE FROM " . $wpdb->posts . " WHERE ID IN (" . implode(", ", $postsIds) . ")";
        $result = $wpdb->query($sql) ;

        $sql = "DELETE FROM " . $wpdb->postmeta . " WHERE post_id IN (" . implode(", ", $postsIds) . ")";
        $result = $wpdb->query($sql) ;


        $this->updateCatsCounts($postsIds);

        $sql = "DELETE FROM " . $wpdb->term_relationships . " WHERE object_id IN (" . implode(", ", $postsIds) . ")";
        $result = $wpdb->query($sql) ;


        /* $sql = "DELETE FROM " . $wpdb->term_taxonomy . " WHERE term_id IN (" . implode(", ", $termsIds) . ")"; */
        /* $result = $wpdb->query($sql) ; */
    }

    private function updateCatsCounts($postsIds)
    {
        global $wpdb;

        $sql ="SELECT  term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id IN (" . implode(", ", $postsIds) . ")";
        $termTaxonomy = array_column($wpdb->get_results($sql), 'term_taxonomy_id');
        $termsCounts = [];
        foreach ($termTaxonomy as $id) {
            $termsCounts[$id] = isset($termsCounts[$id]) ? ++$termsCounts[$id] : 1;
        }

        if ($termsCounts) {
            $case ='';
            $in = implode(',', array_keys($termsCounts));
            foreach ($termsCounts as $ttId=>$count) {
                $case .= " WHEN {$ttId} THEN count - {$count} ";
            }
            $sql = "UPDATE  {$wpdb->term_taxonomy}  SET count  = (CASE term_taxonomy_id {$case} END) WHERE term_taxonomy_id IN ( {$in}  )";
            $wpdb->query($sql);
        }
    }

    private function formatProduct($product)
    {
        $post = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'post_author'    => get_current_user_id(),
            'post_title'     => $product['title'],
            'post_content'   => $product['descr'],
            'post_excerpt'   => '',
            'post_parent'    => 0,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'menu_order'     => 0,
            'post_password'  => '',
            'post_date'      => '',
            'post_date_gmt'  => '',
            'post_name'      => $product['id']
        ];
        return array_values($post);
    }

    private function getImportedProductsIds() {

        global $wpdb;
        $sql = "SELECT post_id,meta_value  FROM " . $wpdb->postmeta . " WHERE meta_key='foreign_id' ";
        return array_column($wpdb->get_results($sql),'meta_value','post_id') ;

    }

    private function insertPosts($products)
    {
        $dbPosts = $this->getImportedProductsIds();
        global $wpdb;
        $chunks = array_chunk($products, $this->productsChunks);
        $selectPlaceHolders=[] ;
        $insertedProductsForeignIds = [];
        foreach ($chunks as $chunk) {
            $productsData = [];
            $productsPlaceHolders=[];
            foreach ($chunk as $product) {
                if(in_array($product['id'], $dbPosts) )
                    continue;

                /* if ($this->getCatIdBySlug($dbCats, $cat['slug'])) { */
                /* continue; */
                /* } */
                $selectPlaceHolders[] = "'%s'";
                $insertedProductsForeignIds[] = $product['id'];
                $productsData = array_merge($productsData, $this->formatProduct($product));
                $productsPlaceHolders[] = "('%s', '%s', '%s', '%s', '%s', '%s', '%s','%s', '%s','%s', '%s','%s', '%s' , '%s')";
            }

            if ($productsData) {
                $sql = "INSERT INTO " . $wpdb->posts . "(
                    post_type, post_status, post_author, post_title, post_content, 
                    post_excerpt, post_parent, comment_status, ping_status,  menu_order, 
                    post_password, post_date,   post_date_gmt, post_name) VALUES " ;
                $sql .= implode(', ', $productsPlaceHolders) . ' ';
                $r = $wpdb->query($wpdb->prepare($sql, $productsData));
            }
        }

        $insertedPosts = [];
        if ($insertedProductsForeignIds) {
            $sql = "SELECT ID, post_name FROM " . $wpdb->posts . " WHERE post_name IN( " .  implode(", ", $selectPlaceHolders) .  ")";
            $insertedPosts = $wpdb->get_results($wpdb->prepare($sql, $insertedProductsForeignIds), ARRAY_A) ;
        }
        /* print_r($insertedPosts); */
        /* print_r($products); */
        $products = $this->productsAddFields($products, $insertedPosts);
        return $products;
    }



    private function insertMeta($products)
    {
        global $wpdb;
        $metaFields = [
           '_price' => 'price',
           '_regular_price'=> 'price',
           '_sale_price'=> 'price',
           '_sku' => 'code',
           '_stock_status'=>'_stock_status',
           'foreign_id'=> 'foreign_id',
        ];

        $chunks = array_chunk($products, $this->productsChunks/2);
        foreach ($chunks as $chunk) {
            $metaData = [];
            $metaPlaceHolders=[];
            foreach ($chunk as $product) {
                if(!$product['inserted'])
                    continue;

                foreach ($metaFields as $k=>$v) {
                    $metaData[] = $product['id'];
                    $metaData[] =  $k;
                    $metaData[] =  $product[$v];
                    $metaPlaceHolders[] = "('%s', '%s', '%s')";
                }
            }
            if ($metaData) {
                $sql = "INSERT INTO " . $wpdb->postmeta . "(post_id, meta_key, meta_value) VALUES " ;
                $sql .= implode(', ', $metaPlaceHolders) . ' ';
                $wpdb->query($wpdb->prepare($sql, $metaData));
            }
        }
    }

    private function insertCategory($products)
    {
        global $wpdb;
        $tt = $this->getTermTaxonomyIds($products);
        $chunks = array_chunk($products, $this->productsChunks*2);
        $countsProductsInCat = [];
        foreach ($chunks as $chunk) {
            $catsData = [];
            $catsPlaceHolders=[];
            foreach ($chunk as $product) {
                if(!$product['inserted'])
                    continue;
                $catsData[] = $product['id'];
                $catsData[] =  $tt[$product['category_id']];
                if (isset($countsProductsInCat[$product['category_id']])) {
                    $countsProductsInCat[$product['category_id']] += 1 ;
                } else {
                    $countsProductsInCat[$product['category_id']] = 1 ;
                }
                $catsData[] =  0;
                $catsPlaceHolders[] = "('%s', '%s', '%s')";
            }
            if ($catsData) {
                $sql = "INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id, term_order) VALUES "  ;
                $sql .= implode(', ', $catsPlaceHolders) . ' ';
                $wpdb->query($wpdb->prepare($sql, $catsData));
            }
        }
        if ($countsProductsInCat) {
            $case ='';
            $in = implode(',', array_keys($countsProductsInCat));
            foreach ($countsProductsInCat as $termId=>$count) {
                $case .= " WHEN {$termId} THEN count + {$count} ";
            }
            $sql = "UPDATE  {$wpdb->term_taxonomy}  SET count  = (CASE term_id {$case} END) WHERE term_id IN ( {$in}  )";
            $wpdb->query($sql);
        }
    }


    private function productsAddFields($products, $insertedPosts)
    {
        $categories = $this->getProductsCategoriesFromDb($products);
        foreach ($products as &$product) {
            $product['foreign_id'] = $product['id'];
            $product['id'] = $this->getProductIdByForeignId($insertedPosts, $product['foreign_id']);
            
            $product['inserted'] = $product['id'] ? true : false;
            
            $product['foreign_category_id'] = $product['category_id'];
            $product['category_id'] = $categories[$product['category_id']] ?? 0;
            $product['_stock_status'] = 'instock';
            if ($product['stock'] == 'no') {
                $product['_stock_status'] = 'outofstock';
            }
        }
        unset($product);
        return $products;
    }

    private function getProductIdByForeignId($posts, $foreign_id)
    {
        $key = array_search($foreign_id, array_column($posts, 'post_name'));
        if ($key !== false) {
            return $posts[$key]['ID'];
        }
    }

    private function getTermTaxonomyIds($products)
    {
        global $wpdb;
        $in = implode(", ", array_unique(array_column($products, 'category_id')));
        $sql = "SELECT term_id, term_taxonomy_id FROM " . $wpdb->term_taxonomy . " WHERE term_id IN (". $in . ")";
        $tt = array_column($wpdb->get_results($sql), 'term_taxonomy_id', 'term_id');
        return $tt;
    }

    private function getProductsCategoriesFromDb($products)
    {
        global $wpdb;
        $sql = "SELECT term_id, meta_value FROM " .$wpdb->termmeta . " WHERE meta_key='foreign_id'";
        $categories = array_column($wpdb->get_results($sql), 'term_id', 'meta_value');
        return $categories;
    }

    private function clearProductsCache()
    {
        /* wp_cache_delete( $post->ID, 'posts' ); */
        /* wp_cache_delete( $post->ID, 'post_meta' ); */

        /* clean_object_term_cache( $post->ID, $post->post_type ); */

        /* wp_cache_delete( 'wp_get_archives', 'general' ); */

        /* _wc_recount_terms_by_product($product->get_id()); */
        /* wp_cache_set('last_changed', microtime(), 'posts'); */
                /* clean_term_cache( $terms, '', false ); */
    /* wp_cache_delete( $object_id, $taxonomy . '_relationships' ); */
    /* wp_cache_delete( 'last_changed', 'terms' ); */
    }

    private function getProducts($jsonString)
    {
        $i = 1;
        $products = [];
        $jsonProducts = $jsonString['products']['product'] ;

        foreach ($jsonProducts as $jsonProduct) {
            if (!$jsonProduct['properties']) {
                continue;
            }

            $i++;
            if ($i >5) {
                break;
            }
            $products[] =  $jsonProduct;
        }

        return $products;
    }

}
