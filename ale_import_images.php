<?php

class AleImportImages
{
    private $imagesChunks;

    public function __construct($imagesChunks = 20)
    {
        $this->imagesChunks = $imagesChunks;
    }

    public function getImportedProductsIdsFromDb()
    {
        global $wpdb;
        $sql = "SELECT post_id,meta_value  FROM " . $wpdb->postmeta . " WHERE meta_key='foreign_id' ";

        return array_column($wpdb->get_results($sql), 'post_id', 'meta_value') ;
    }

    public function importImages($jsonString)
    {
        $imagesUrls = $this->getImages($jsonString);
        $productsIds = $this->getImportedProductsIdsFromDb();
        $downloadedImages = $this->downloadFiles($imagesUrls, $productsIds);
        $dbAttacments = $this->insertAttachmentsToDb($downloadedImages);
        /* print_r($dbAttacments); */
        $this->bindImageToProduct($dbAttacments);
    }

    public function bindImageToProduct($dbAttacments)
    {
        global $wpdb;
        $chunks = array_chunk($dbAttacments, $this->imagesChunks);
        foreach ($chunks as $chunk) {
            $metaData = [];
            $metaPlaceHolders=[];
            $postsIds = [];
            foreach ($chunk as $attacment) {
                $postsIds[] = $attacment['id'];
                $metaData[] = $attacment['id'];
                $metaData[] =  '_thumbnail_id';
                $metaData[] =  $attacment["attachment_id"];
                $metaPlaceHolders[] = "('%s', '%s', '%s')";
            }
            if ($metaData) {
                $sql = "DELETE FROM " . $wpdb->postmeta . " WHERE meta_key= '_thumbnail_id' AND post_id IN(" . implode(',',$postsIds) . ")";
                /* print_r($sql); */
                $wpdb->query($sql );

                $sql = "INSERT INTO " . $wpdb->postmeta . "(post_id, meta_key, meta_value) VALUES " ;
                $sql .= implode(', ', $metaPlaceHolders) . ' ';
                $wpdb->query($wpdb->prepare($sql, $metaData));
            }
        }
        /* print_r($dbAttacments); */
    }

    private function downloadFiles($imagesUrls, $productsIds)
    {
        $i=0;
        $downloadedImages = [];
        foreach ($imagesUrls as $foreignId=>$imgUrl) {
            if (!isset($productsIds[$foreignId])) {
                continue;
            }

            $i++;
            if ($i > 3) {
                /* continue; */
            }
            $postId = $productsIds[$foreignId];
            $attacmentData = $this->uploadImgFile($imgUrl, $postId);
            $downloadedImages[] = [
                'foreign_id' => $foreignId,
                'id' => $postId,
                'attachment'=> $attacmentData,
                'guid' => $attacmentData['guid'],
                'file' => $attacmentData['file'],
            ];
        }
        return $downloadedImages;
    }
    private function formatAttachment($attachment)
    {
        $now = date("Y-m-d H:i:s");
        $post = [
                'post_name'=> $attachment['post_name'],
                'post_date' => $now,
                'post_date_gmt' => $now,
                'post_modified' => $now,
                'post_modified_gmt' => $now,
                'post_type' => 'attachment',
                'post_parent' => $attachment['post_parent'],
                'guid' => $attachment['guid'],
                'post_mime_type' => $attachment['post_mime_type'],
                'post_title' => $attachment['post_title'],
                'post_content' => '',
                'post_status' => 'inherit'
        ];
        return array_values($post);
    }
    private function insertAttachmentsToDb($images)
    {
        global $wpdb;
        $chunks = array_chunk($images, $this->imagesChunks);
        $selectPlaceHolders=[] ;
        foreach ($chunks as $chunk) {
            $attachmentData = [];
            $attachmentPlaceHolders=[];
            foreach ($chunk as $image) {
                $selectPlaceHolders[] = "'%s'";
                $attachmentData = array_merge($attachmentData, $this->formatAttachment($image['attachment']));
                $attachmentPlaceHolders[] = "('%s','%s','%s','%s', '%s', '%s','%s', '%s', '%s', '%s', '%s', '%s' )";
            }

            if ($attachmentData) {
                $sql = "INSERT INTO " . $wpdb->posts . "(post_name, post_date, post_date_gmt, post_modified, post_modified_gmt,
                    post_type, post_parent, guid, post_mime_type, post_title, post_content, post_status
                    ) VALUES " ;
                $sql .= implode(', ', $attachmentPlaceHolders) . ' ';
                /* print_r($wpdb->prepare($sql, $attachmentData)); */
                $r = $wpdb->query($wpdb->prepare($sql, $attachmentData));
            }
        }

        $inserted = [];
        if ($selectPlaceHolders) {
            $sql = "SELECT ID, guid FROM " . $wpdb->posts . " WHERE guid IN( " .  implode(", ", $selectPlaceHolders) .  ")";
            $inserted = $wpdb->get_results($wpdb->prepare($sql, array_column($images, 'guid')), ARRAY_A) ;
            $inserted = array_column($inserted, 'ID', 'guid');
        }

        foreach ($images as &$img) {
            $attachmentId = $inserted[$img['guid']];
            $img['attachment_id'] = $attachmentId;
            
            //TODO this is slow functions
            $attachmentData = wp_generate_attachment_metadata($attachmentId, $img['file']);
            wp_update_attachment_metadata($attachmentId, $attachmentData);
            $file = _wp_relative_upload_path( $img['file'] );
	    update_post_meta( $attachmentId, '_wp_attached_file', $file );
        }
        unset($img);
        return $images;
    }

    private function uploadImgFile($file, $postId)
    {
        $filename = basename($file);
        $upload_file = wp_upload_bits($filename, null, file_get_contents($file));
        if (!$upload_file['error']) {
            $wp_upload_dir = wp_upload_dir();
            $wp_filetype = wp_check_filetype($filename, null);
            $name = preg_replace('/\.[^.]+$/', '', $filename);
            $attachmentData = array(
                'file' => $upload_file['file'],
                'post_parent' => $postId,
                'guid'           => $wp_upload_dir['url'] . '/' . $filename,
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => $name,
                'post_name' => $name,
            );
        }
        return $attachmentData;
    }


    private function getImages($jsonString)
    {
        $jsonProducts = $jsonString['products']['product'] ;
        return array_column($jsonProducts, 'image', 'id');
    }

}
