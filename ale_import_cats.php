<?php

class AleImportCats
{
    private $catsChunks;

    public function __construct($catsChunks = 20)
    {
        $this->catsChunks = $catsChunks;
    }

    public function insertCats($jsonString)
    {
        $cats = $this->getCats($jsonString);
        if (!$cats) {
            return;
        }

        $cats = $this->insertTerms($cats);

        $this->insertTermMeta($cats);

        $this->insertTaxonomyData($cats);

        $this->clearCatsCache($cats);

        return $cats;
    }

    public function deleteCats()
    {
        global $wpdb;
        $sql = "SELECT term_id  FROM " . $wpdb->termmeta . " WHERE meta_key='foreign_id' ";
        $termsIds = $wpdb->get_col($sql) ;

        print_r(count($termsIds));
        if (!$termsIds) {
            return;
        }

        $sql = "DELETE FROM " . $wpdb->terms . " WHERE term_id IN (" . implode(", ", $termsIds) . ")";
        $result = $wpdb->query($sql) ;

        $sql = "DELETE FROM " . $wpdb->term_taxonomy . " WHERE term_id IN (" . implode(", ", $termsIds) . ")";
        $result = $wpdb->query($sql) ;


        $sql = "DELETE FROM " . $wpdb->termmeta . " WHERE meta_key='foreign_id' ";
        $result = $wpdb->query($sql) ;

        $this->clearCatsCache($termsIds);
    }

    private function getCats($jsonString)
    {
        $i = 1;
        $cats = [];
        $jsonCats = $jsonString['categories']['category'] ;

        foreach ($jsonCats as $jsonCat) {
            $i++;
            /* if ($i >20) */
            /* break; */

            $name = wp_unslash($jsonCat['title']);
            $key = array_search($name, array_column($cats, 'name'));
            if ($key !== false) {
                $name = wp_unslash($jsonCat['title'] . '_' . $jsonCat['id']);
            }

            $slug = sanitize_title($name);
            $cats[] = [
                    "foreign_id" => $jsonCat['id'],
                    "name" => $name,
                    "slug" => $slug,
                    "foreign_parent_id" => $jsonCat['parent_id'],
                ];
        }

        foreach ($cats as &$cat) {
            $cat['parent_slug'] =  $this->getParentSlug($cats, $cat['foreign_parent_id']);
        }
        return $cats;
    }

    private function getParentSlug($cats, $parent_id)
    {
        $key = array_search($parent_id, array_column($cats, 'foreign_id'));
        if ($key !== false) {
            return $cats[$key]['slug'];
        }
    }


    private function getCatIdBySlug($terms, $slug)
    {
        if (!$slug) {
            return null;
        }

        $key = array_search($slug, array_column($terms, 'slug'));
        if ($key !== false) {
            return $terms[$key]['term_id'];
        }
    }

    private function clearCatsCache($cats)
    {
        if (isset($cats[0]['id'])) {
            $catsIds = wp_list_pluck($cats, 'id');
        } else {
            $catsIds = $cats;
        }

        wp_cache_delete_multiple($catsIds, 'terms');
        clean_taxonomy_cache("product_cat");
        wp_cache_set('last_changed', microtime(), 'terms');
    }

    private function getAllDbCats()
    {
        $termsArr = [];
        $terms = get_terms('product_cat', ['hide_empty' => false]);
        foreach ($terms as $term) {
            $termsArr[] = [
                'slug' => $term->slug,
                'term_id' => $term->term_id,
            ];
        }
        return $termsArr;
    }

    private function insertTerms($cats)
    {
        $dbCats = $this->getAllDbCats() ;

        global $wpdb;
        $terms = [];
        $chunks = array_chunk($cats, $this->catsChunks);
        $select_place_holders=[] ;
        $insertedSlugs = [];
        foreach ($chunks as $chunk) {
            $termsData = [];
            $terms_place_holders=[];
            foreach ($chunk as $cat) {
                if ($this->getCatIdBySlug($dbCats, $cat['slug'])) {
                    continue;
                }

                $select_place_holders[] = "'%s'";
                $insertedSlugs[] = $cat['slug'];
                $termsData[] = $cat['name'];
                $termsData[] = $cat['slug'];
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

        $terms = array_merge($dbCats, $terms);
        /* $cats = array_filter($cats, fn($e) => in_array($e['slug'], $insertedSlugs)); */
        if ($cats) {
            $cats = $this->addDataToCats($cats, $terms, $insertedSlugs);
        }

        print_r(count($insertedSlugs));
        return $cats;
    }

    private function addDataToCats($cats, $terms, $insertedSlugs)
    {
        foreach ($cats as &$cat) {
            $cat['id'] = $this->getCatIdBySlug($terms, $cat['slug']);
            $cat['parent_id'] = $this->getCatIdBySlug($terms, $cat['parent_slug']);
            $cat['inserted'] =  in_array($cat['slug'], $insertedSlugs);
        }
        return $cats;
    }

    private function insertTaxonomyData($cats)
    {
        global $wpdb;
        $chunks = array_chunk($cats, $this->catsChunks);
        foreach ($chunks as $chunk) {
            $taxonomyData = [];
            $taxonomy_place_holders=[];
            foreach ($chunk as $cat) {
                if (!$cat['inserted']) {
                    continue;
                }

                $taxonomy_place_holders[] = "('%d', '%s', '%d')";
                $taxonomyData[] = $cat['id'];
                $taxonomyData[] = 'product_cat';
                $taxonomyData[] = $cat['parent_id'];
            }
            if ($taxonomyData) {
                $sql = "INSERT INTO " . $wpdb->term_taxonomy . "(term_id, taxonomy, parent) VALUES " ;
                $sql .= implode(', ', $taxonomy_place_holders);
                $result = $wpdb->query($wpdb->prepare($sql, $taxonomyData));
            }
        }
    }

    private function insertTermMeta($cats)
    {
        global $wpdb;
        $chunks = array_chunk($cats, $this->catsChunks);
        foreach ($chunks as $chunk) {
            $termMetaData = [];
            $meta_place_holders=[]  ;
            foreach ($chunk as $cat) {
                if (!$cat['inserted']) {
                    continue;
                }

                $meta_place_holders[] = "('%d', '%s', '%d')";
                $termMetaData[] = $cat['id'];
                $termMetaData[] = 'foreign_id';
                $termMetaData[] = $cat['foreign_id'];
            }

            if ($termMetaData) {
                $sql = "INSERT INTO " . $wpdb->termmeta . "(term_id, meta_key, meta_value) VALUES " ;
                $sql .= implode(', ', $meta_place_holders);
                $wpdb->query($wpdb->prepare($sql, $termMetaData));
            }
        }
    }
}
