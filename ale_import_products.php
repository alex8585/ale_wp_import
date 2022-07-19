<?php

class AleImportProducts
{
    private $productsChunks;

    public function __construct($productsChunks = 20)
    {
        $this->productsChunks = $productsChunks;
    }

    public function insertProducts($jsonString)
    {
        $products = $this->getProducts($jsonString);
        if (!$products) {
            return;
        }




        $products = $this->insertPosts($products);
        $this->insertMeta($products);
        $this->insertAttributes($products);
        /* $this->insertCategory($products); */
    }

    private function sanitizeTaxName($attr)
    {
        $taxName = wc_sanitize_taxonomy_name(stripslashes(substr($this->translit($attr), 0, 20)));
        return $taxName;
    }

    private function insertAttributes($products)
    {
        global $wpdb;
        $attrsArr = [];
        foreach ($products as $product) {
            foreach ($product['properties']['property'] as $propArr) {
                $attr = $propArr['name'];
                $value = $propArr['value'];
                /* if (!isset($attrsArr[$attr][$value])) { */
                /* $attrsArr[$attr] = []; */
                /* } */

                /* if (!in_array($value, $attrsArr[$attr])) { */
                $attrsArr[$attr][$value][] = $product['id'];
                /* } */
            }
        }

        $chunks = array_chunk(array_keys($attrsArr), $this->productsChunks/2);
        foreach ($chunks as $attrs) {
            $attrsData = [];
            $attrsPlaceHolders=[];
            foreach ($attrs as $attr) {
                $attrsData[] = $attr;
                $attrsData[] =  $this->sanitizeTaxName($attr);
                $attrsData[] =  'select';
                $attrsData[] =  "menu_order";
                $attrsData[] =  0;
                $attrsPlaceHolders[] = "('%s', '%s', '%s' ,'%s','%s')";
            }

            if ($attrsData) {
                $sql = "INSERT INTO  {$wpdb->prefix}woocommerce_attribute_taxonomies  (
                    attribute_label,  attribute_name , attribute_type , attribute_orderby ,attribute_public ) VALUES " ;

                $sql .= implode(', ', $attrsPlaceHolders) . ' ';
                $wpdb->query($wpdb->prepare($sql, $attrsData));
            }
        }
        $termsAttrs = $this->insertAttrsTerms($attrsArr);
        $termsAttrs = $this->insertTaxonomyData($termsAttrs);
        /* print_r($termsAttrs); */
        /* die; */
        delete_transient('wc_attribute_taxonomies');
        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
        $this->bindAttrsToProducts($termsAttrs);
    }


    private function bindAttrsToProducts($termsAttrs)
    {
        global $wpdb;

        $chunks = array_chunk($termsAttrs, $this->productsChunks/4);
        foreach ($chunks as $attrs) {
            $attrsData = [];
            $attrsPlaceHolders=[];
            foreach ($attrs as $attr) {
                foreach ($attr['productsIds'] as $productId) {
                    $attrsData[] = $productId;
                    $attrsData[] = $attr['tt_id'];
                    $attrsPlaceHolders[] = "('%s', '%s' )";
                }
            }
            if ($attrsData) {
                $sql = "INSERT INTO  {$wpdb->term_relationships} (
                    object_id,  term_taxonomy_id   ) VALUES " ;

                $sql .= implode(', ', $attrsPlaceHolders) . ' ';
                $wpdb->query($wpdb->prepare($sql, $attrsData));
            }
        }
    }

    private function insertTaxonomyData($termsAttrs)
    {
        global $wpdb;
        $chunks = array_chunk($termsAttrs, $this->productsChunks);
        foreach ($chunks as $chunk) {
            $taxonomyData = [];
            $taxonomy_place_holders=[];
            foreach ($chunk as $tt) {
                /* print_r($tt); */

                /* if (!$cat['inserted']) { */
                /* continue; */
                /* } */

                $taxonomy_place_holders[] = "('%d', '%s', '%d')";
                $taxonomyData[] = $tt['id'];
                $taxonomyData[] = $tt['taxonomy'];
                $taxonomyData[] = 0;
            }
            if ($taxonomyData) {
                $sql = "INSERT INTO " . $wpdb->term_taxonomy . "(term_id, taxonomy, parent) VALUES " ;
                $sql .= implode(', ', $taxonomy_place_holders);
                $result = $wpdb->query($wpdb->prepare($sql, $taxonomyData));
            }
        }

        $tt = $this->getAttrsTermTaxonomyIds($termsAttrs);
        foreach ($termsAttrs as &$attr) {
            $attr['tt_id'] = $tt[$attr['id']];
        }
        unset($attr);
        return $termsAttrs;
    }

    private function getAttrsTermTaxonomyIds($termAttrs)
    {
        global $wpdb;
        $in = implode(", ", array_unique(array_column($termAttrs, 'id')));
        $sql = "SELECT term_id, term_taxonomy_id FROM " . $wpdb->term_taxonomy . " WHERE term_id IN (". $in . ")";
        $tt = array_column($wpdb->get_results($sql), 'term_taxonomy_id', 'term_id');
        return $tt;
    }

    private function insertAttrsTerms($attrsArr)
    {

        /* $dbCats = $this->getAllDbCats() ; */
        $termsAttrs = [];
        foreach ($attrsArr as $attrName=>$termsArr) {
            foreach ($termsArr as $t=>$prodIds) {
                $name = wp_unslash($t);
                $slug = sanitize_title($name);
                if (in_array($slug, array_column($termsAttrs, 'slug'))) {
                    continue;
                }
                $termsAttrs[] = [
                    'taxonomy'=> 'pa_' . $this->sanitizeTaxName($attrName),
                    'name' => $name,
                    'slug'=> $slug,
                    'productsIds'=>$prodIds,
                ];
            }
        }
        global $wpdb;
        $terms = [];
        $chunks = array_chunk($termsAttrs, $this->productsChunks/2);
        $select_place_holders=[] ;
        $insertedSlugs = [];
        foreach ($chunks as $chunk) {
            $termsData = [];
            $terms_place_holders=[];
            foreach ($chunk as $attr) {
                /* if ($this->getCatIdBySlug($dbCats, $cat['slug'])) { */
                /* continue; */
                /* } */

                $select_place_holders[] = "'%s'";
                $insertedSlugs[] = $attr['slug'];
                $termsData[] = $attr['name'];
                $termsData[] = $attr['slug'];
                $terms_place_holders[] = "('%s', '%s')";
            }

            if ($termsData) {
                $sql = "INSERT INTO " . $wpdb->terms . "(name, slug) VALUES " ;
                $sql .= implode(', ', $terms_place_holders) . ' ';
                $wpdb->query($wpdb->prepare($sql, $termsData));
            }
        }

        $terms = [];
        if ($insertedSlugs) {
            $sql = "SELECT term_id, slug FROM " . $wpdb->terms . " WHERE slug IN( " .  implode(", ", $select_place_holders) .  ")";
            $terms = $wpdb->get_results($wpdb->prepare($sql, $insertedSlugs), ARRAY_A) ;
        }
        /* $terms = array_merge($dbCats, $terms); */

        $termsAttrs = $this->addDataToTermAttrs($termsAttrs, $terms);
        return $termsAttrs;
    }

    private function addDataToTermAttrs($termsAttrs, $terms)
    {
        foreach ($termsAttrs as &$termAttr) {
            $termAttr['id'] = $this->getTermIdBySlug($terms, $termAttr['slug']);
        }
        unset($termAttr);
        return $termsAttrs;
    }

    private function getTermIdBySlug($terms, $slug)
    {
        if (!$slug) {
            return null;
        }

        $key = array_search($slug, array_column($terms, 'slug'));
        if ($key !== false) {
            return $terms[$key]['term_id'];
        }
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

    private function productsAddFields($products, $insertedPosts)
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

    private function getTermTaxonomyIds($products)
    {
        global $wpdb;
        $in = implode(", ", array_unique(array_column($products, 'category_id')));
        $sql = "SELECT term_id, term_taxonomy_id FROM " . $wpdb->term_taxonomy . " WHERE term_id IN (". $in . ")";
        $tt = array_column($wpdb->get_results($sql), 'term_taxonomy_id', 'term_id');
        return $tt;
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

    public function translit($str)
    {
        $rus = array('І','і','Ї','ї', 'А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ё', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я', 'а', 'б', 'в', 'г', 'д', 'е', 'ё', 'ж', 'з', 'и', 'й', 'к', 'л', 'м', 'н', 'о', 'п', 'р', 'с', 'т', 'у', 'ф', 'х', 'ц', 'ч', 'ш', 'щ', 'ъ', 'ы', 'ь', 'э', 'ю', 'я');
        $lat = array('I','i','I','i','A', 'B', 'V', 'G', 'D', 'E', 'E', 'Gh', 'Z', 'I', 'Y', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'F', 'H', 'C', 'Ch', 'Sh', 'Sch', 'Y', 'Y', 'Y', 'E', 'Yu', 'Ya', 'a', 'b', 'v', 'g', 'd', 'e', 'e', 'gh', 'z', 'i', 'y', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'u', 'f', 'h', 'c', 'ch', 'sh', 'sch', 'y', 'y', 'y', 'e', 'yu', 'ya');
        return str_replace($rus, $lat, $str);
    }
}
