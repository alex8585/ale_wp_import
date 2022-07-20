<?php

class AleImportAttributes
{
    private $attributesChunks;

    public function __construct($attributesChunks = 20)
    {
        $this->attributesChunks = $attributesChunks;
    }

    public function insertAttributes($products)
    {
        $attrsArr = $this->insertWoocommeerceAttributes($products) ;

        $termsAttrs = $this->insertAttrsTerms($attrsArr);
        $termsAttrs = $this->insertTaxonomyData($termsAttrs);
        delete_transient('wc_attribute_taxonomies');
        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
        $this->bindAttrsToProducts($termsAttrs);
        $this->insertAttributesPostMetaData($termsAttrs);
    }

    private function insertAttributesPostMetaData($termsAttrs)
    {
        global $wpdb;
        $productsMeta =[];
        foreach ($termsAttrs as $attr) {
            foreach ($attr['productsIds'] as $productId) {
                $productsMeta[$productId]['pa_tax_arr'][] = $attr['taxonomy'];
                $productsMeta[$productId]['product_id'] = $productId;
            }
        }


        $chunks = array_chunk($productsMeta, $this->attributesChunks);
        foreach ($chunks as $productsMeta) {
            $metaData = [];
            $metaPlaceHolders=[];
            foreach ($productsMeta as $product) {
                $metaData[] = $product['product_id'];
                $metaData[] = '_product_attributes' ;
                $metaData[] = $this->createProductAttrsString($product['pa_tax_arr']) ;
                $metaPlaceHolders[] = "('%s', '%s', '%s' )";
            }

            if ($metaData) {
                $sql = "INSERT INTO " . $wpdb->postmeta . "(post_id, meta_key, meta_value) VALUES " ;
                $sql .= implode(', ', $metaPlaceHolders) . ' ';
                $wpdb->query($wpdb->prepare($sql, $metaData));
            }
        }
    }
    private function createProductAttrsString($attrs)
    {
        $toSerializeArr=[];
        $i=0;
        foreach ($attrs as $attr) {
            $toSerializeArr[$attr] = [
                "name" => $attr,
                "value" => '',
                "position" => $i,
                "is_visible" => 1,
                "is_variation" => 0,
                "is_taxonomy" => 1
            ];
            $i++;
        }
        return serialize($toSerializeArr);
    }
    private function getAllDbWoocomeerceAttrs()
    {
        global $wpdb;
        $sql = "SELECT attribute_name FROM   {$wpdb->prefix}woocommerce_attribute_taxonomies  " ;
        $attributes = $wpdb->get_col($sql);
        return $attributes ? $attributes : [];
    }

    private function insertWoocommeerceAttributes($products)
    {
        global $wpdb;
        $allDbWoocAttrs = $this->getAllDbWoocomeerceAttrs();
        $attrsArr = [];
        foreach ($products as $product) {
            if (!$product['inserted']) {
                continue;
            }
            foreach ($product['properties']['property'] as $propArr) {
                $attr = $propArr['name'];
                $value = $propArr['value'];
                $attrsArr[$attr][$value][] = $product['id'];
            }
        }

        $chunks = array_chunk(array_keys($attrsArr), $this->attributesChunks);
        foreach ($chunks as $attrs) {
            $attrsData = [];
            $attrsPlaceHolders=[];
            foreach ($attrs as $attr) {
                $attributeName = $this->sanitizeTaxName($attr);
                if (in_array($attributeName, $allDbWoocAttrs)) {
                    continue;
                }

                $attrsData[] = $attr;
                $attrsData[] =  $attributeName;
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

        return $attrsArr;
    }

    private function getAllDbTerms($slugsArr)
    {
        global $wpdb;

        if (!$slugsArr) {
            return [];
        }

        $sql = "SELECT slug FROM " . $wpdb->terms . " WHERE slug IN( " . implode(", ", array_map(fn ($s) => "'{$s}'", $slugsArr))   .  ")";
        $terms = $wpdb->get_col($sql) ;
        return $terms;
    }
    private function insertAttrsTerms($attrsArr)
    {
        $termsAttrs = [];
        $slugsArr = [];
        foreach ($attrsArr as $attrName=>$termsArr) {
            foreach ($termsArr as $t=>$prodIds) {
                $name = wp_unslash($t);
                $slug = sanitize_title($name);
                if (in_array($slug, array_column($termsAttrs, 'slug'))) {
                    continue;
                }
                $slugsArr[] = $slug;
                $termsAttrs[] = [
                    'taxonomy'=> 'pa_' . $this->sanitizeTaxName($attrName),
                    'name' => $name,
                    'slug'=> $slug,
                    'productsIds'=>$prodIds,
                ];
            }
        }

        $dbTerms = $this->getAllDbTerms($slugsArr);

        global $wpdb;
        $terms = [];
        $chunks = array_chunk($termsAttrs, $this->attributesChunks);
        $select_place_holders=[] ;
        $insertedSlugs = [];
        foreach ($chunks as $chunk) {
            $termsData = [];
            $terms_place_holders=[];
            foreach ($chunk as $attr) {
                if (in_array($attr['slug'], $dbTerms)) {
                    continue;
                }
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

    private function insertTaxonomyData($termsAttrs)
    {
        global $wpdb;
        $chunks = array_chunk($termsAttrs, $this->attributesChunks);
        foreach ($chunks as $chunk) {
            $taxonomyData = [];
            $taxonomy_place_holders=[];
            foreach ($chunk as $tt) {
                if (!$tt['inserted']) {
                    continue;
                }

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
            $attr['tt_id'] = $tt[$attr['id']] ?? null;
        }
        unset($attr);
        return $termsAttrs;
    }


    private function bindAttrsToProducts($termsAttrs)
    {
        global $wpdb;

        $chunks = array_chunk($termsAttrs, $this->attributesChunks);
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

    private function sanitizeTaxName($attr)
    {
        $taxName = wc_sanitize_taxonomy_name(stripslashes(substr($this->translit($attr), 0, 20)));
        return $taxName;
    }

    private function getAttrsTermTaxonomyIds($termAttrs)
    {
        global $wpdb;
        $tt =[];
        $in = implode(", ", array_unique(array_column($termAttrs, 'id')));
        if ($in) {
            $sql = "SELECT term_id, term_taxonomy_id FROM " . $wpdb->term_taxonomy . " WHERE term_id IN (". $in . ")";
            $tt = array_column($wpdb->get_results($sql), 'term_taxonomy_id', 'term_id');
        }
        return $tt;
    }

    private function addDataToTermAttrs($termsAttrs, $terms)
    {
        $notInsertedSlugs = [];
        foreach ($termsAttrs as &$termAttr) {
            $termAttr['id'] = $this->getTermIdBySlug($terms, $termAttr['slug']);
            $termAttr['inserted'] = isset($termAttr['id']) ? true : false;
            if (!$termAttr['inserted']) {
                $notInsertedSlugs[] = $termAttr['slug'];
            }
        }

        unset($termAttr);
        if ($notInsertedSlugs) {
            global $wpdb;
            $dbTerms = [];
            $sql = "SELECT term_id, slug FROM " . $wpdb->terms . " WHERE slug IN( " .  implode(", ", array_map(fn ($s) => "'{$s}'", $notInsertedSlugs)) .  ")";
            $dbTerms = $wpdb->get_results($sql, ARRAY_A) ;
            foreach ($termsAttrs as &$termAttr) {
                if($termAttr['inserted'])
                    continue;
                    $termAttr['id'] = $this->getTermIdBySlug($dbTerms, $termAttr['slug']);
            }
            unset($termAttr);
        }


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

    public function translit($str)
    {
        $rus = array('І','і','Ї','ї', 'А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ё', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я', 'а', 'б', 'в', 'г', 'д', 'е', 'ё', 'ж', 'з', 'и', 'й', 'к', 'л', 'м', 'н', 'о', 'п', 'р', 'с', 'т', 'у', 'ф', 'х', 'ц', 'ч', 'ш', 'щ', 'ъ', 'ы', 'ь', 'э', 'ю', 'я');
        $lat = array('I','i','I','i','A', 'B', 'V', 'G', 'D', 'E', 'E', 'Gh', 'Z', 'I', 'Y', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'F', 'H', 'C', 'Ch', 'Sh', 'Sch', 'Y', 'Y', 'Y', 'E', 'Yu', 'Ya', 'a', 'b', 'v', 'g', 'd', 'e', 'e', 'gh', 'z', 'i', 'y', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'u', 'f', 'h', 'c', 'ch', 'sh', 'sch', 'y', 'y', 'y', 'e', 'yu', 'ya');
        return str_replace($rus, $lat, $str);
    }
}
