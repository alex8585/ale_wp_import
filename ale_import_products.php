<?php

class AleImportProducts
{
    private $productsChunks;

    public function __construct($productsChunks = 20)
    {
        $this->productsChunks = $productsChunks;
    }

    private function formatProduct($product)
    {
        /* 'post_date'      => gmdate('Y-m-d H:i:s', $product->get_date_created('edit')->getOffsetTimestamp()), */
        /* 'post_date_gmt'  => gmdate('Y-m-d H:i:s', $product->get_date_created('edit')->getTimestamp()), */
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

    private function getProductIdByForeignId($posts, $foreign_id)
    {
        $key = array_search($foreign_id, array_column($posts, 'post_name'));
        if ($key !== false) {
            return $posts[$key]['ID'];
        }
    }

    private function insertPosts($products)
    {
        global $wpdb;
        $chunks = array_chunk($products, $this->productsChunks);
        $selectPlaceHolders=[] ;
        $insertedProductsForeignIds = [];
        foreach ($chunks as $chunk) {
            $productsData = [];
            $productsPlaceHolders=[];
            foreach ($chunk as $product) {
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

    public function productsAddFields($products, $insertedPosts)
    {
        $categories = $this->getProductsCategoriesFromDb($products);
        foreach ($products as &$product) {
            $product['foreign_id'] = $product['id'];
            $product['id'] = $this->getProductIdByForeignId($insertedPosts, $product['foreign_id']);
            
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

    public function getProductsCategoriesFromDb($products) {
        global $wpdb;
        $sql = "SELECT term_id, meta_value FROM " .$wpdb->termmeta . " WHERE meta_key='foreign_id'";
        $categories = array_column($wpdb->get_results($sql), 'term_id','meta_value');
        return $categories;
    }

    public function clearProductsCache()
    {
        /* wp_cache_delete( $post->ID, 'posts' ); */
        /* wp_cache_delete( $post->ID, 'post_meta' ); */

        /* clean_object_term_cache( $post->ID, $post->post_type ); */

        /* wp_cache_delete( 'wp_get_archives', 'general' ); */

        /* _wc_recount_terms_by_product($product->get_id()); */
        /* wp_cache_set('last_changed', microtime(), 'posts'); */
    }

    public function insertMeta($products)
    {
        global $wpdb;
        $metaFields = [
           '_price' => 'price',
           '_regular_price'=> 'price',
           '_sale_price'=> 'price',
           '_sku' => 'code',
           '_stock_status'=>'_stock_status',
        ];

        $chunks = array_chunk($products, $this->productsChunks/2);
        foreach ($chunks as $chunk) {
            $metaData = [];
            $metaPlaceHolders=[];
            foreach ($chunk as $product) {
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
                /* print_r($wpdb->prepare($sql, $metaData)); */
                $wpdb->query($wpdb->prepare($sql, $metaData));
            }
        }
    }

    public function insertProducts($jsonString)
    {
        $products = $this->getProducts($jsonString);
        if (!$products) {
            return;
        }

        $products = $this->insertPosts($products);
        $this->insertMeta($products);
        $this->insertCategory($products); 
    }

    public function getTermTaxonomyIds($products) {
        global $wpdb;
        $in = implode(", ", array_unique(array_column($products, 'category_id')));
        $sql = "SELECT term_id, term_taxonomy_id FROM " . $wpdb->term_taxonomy . " WHERE term_id IN (". $in . ")";
        $tt = array_column($wpdb->get_results($sql), 'term_taxonomy_id','term_id');
        return $tt;
    }

    public function insertCategory($products) {

        global $wpdb;
        $tt = $this->getTermTaxonomyIds($products);
        $chunks = array_chunk($products, $this->productsChunks*2);
        foreach ($chunks as $chunk) {
            $catsData = [];
            $catsPlaceHolders=[];
            foreach ($chunk as $product) {
                $catsData[] = $product['id'];
                $catsData[] =  $tt[$product['category_id']];
                $catsData[] =  0;
                $catsPlaceHolders[] = "('%s', '%s', '%s')";
            }
            if ($catsData) {
                $sql = "INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id, term_order) VALUES "  ;
                $sql .= implode(', ', $catsPlaceHolders) . ' ';
                print_r($wpdb->prepare($sql, $catsData));
                $wpdb->query($wpdb->prepare($sql, $catsData));
            }
        }

	/* clean_term_cache( $terms, '', false ); */
	/* wp_cache_delete( $object_id, $taxonomy . '_relationships' ); */
	/* wp_cache_delete( 'last_changed', 'terms' ); */


	       
    }

    public function deleteProducts()
    {
        global $wpdb;
    }

    private function getProducts($jsonString)
    {
        $i = 1;
        $products = [];
        $jsonProducts = $jsonString['products']['product'] ;

        foreach ($jsonProducts as $jsonProduct) {
            if ($jsonProduct['properties']) {
                continue;
            }

            $i++;
            if ($i >3) {
                break;
            }
            $products[] =  $jsonProduct;
        }

        return $products;
    }
}
