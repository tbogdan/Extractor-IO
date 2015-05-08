<?php
// Ensure we are not being accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

// Check the EIO_Extractor class does not already exist.
if (!class_exists('EIO_Extractor')):

/**
 * Extractor IO Extractor
 *
 * This class both extracts the data using Import IO from a specified URL
 * and converts it into a WordPress post.
 *
 * @class      EIO_Extractor
 * @category   Parser Class
 * @version    1.0.0
 * @since      2.0.0
 * @author     Nialto Services
 * @copyright  2015 Nialto Services
 * @license    http://opensource.org/licenses/GPL-3.0
 * @package    ExtractorIO
 * @subpackage Includes/Core
 */
class EIO_Extractor {
	/**
	 * Extraction failed.
	 */
	const EXTRACTION_FAILED = 1;
	
	/**
	 * The extracted data was null.
	 */
	const EXTRACTED_DATA_NULL = 2;
	
	/**
	 * Failed to insert WordPress post.
	 */
	const POST_INSERT_FAILED = 3;
	
	/**
	 * WordPress post inserted.
	 */
	const POST_EXTRACTED = 4;
	
	/**
	 * The GUID of the connector to use when extracting data.
	 *
	 * @var array
	 * @access private
	 * @since 1.0.0
	 */
	private $connector_guid = null;
	
	/**
	 * The array of mapping data for the connector.
	 *
	 * @var array
	 * @access private
	 * @since 1.0.0
	 */
	private $mapping = null;
	
	/**
	 * Setup an instance of the EIO_Extractor class.
	 *
	 * Setup an instance of this class with the specified
	 * connector.
	 *
	 * @access public
	 * @since 1.0.0
	 * @param array $connector The connector this extractor uses.
	 */
	public function __construct($connector_guid) {
		if (false === is_string($connector_guid) || empty($connector_guid)) {
			throw new BadFunctionCallException('You must provide a valid connector GUID.');
		}
		
		$this->connector_guid = $connector_guid;
		$this->mapping = EIO()->connector_mappings->get_option($connector_guid);
	}
	
	/**
	 * Extract data from a URL
	 *
	 * Using Import IO, extract data from the specified URL.
	 *
	 * @access public
	 * @since 1.0.0
	 * @param string $url The URL data should be extracted from.
	 * @return array|null The extracted data (if successful) or null (if unsuccessful)
	 */
	public function extract_data($url) {
		if (EIO()->import_io) {
			return EIO()->import_io->extractData($this->connector_guid, $url);
		}
		
		return null;
	}
	
	/**
	 * Build post from URL
	 *
	 * This will extract data from the specified URL,
	 * then parse it into a WordPress post.
	 *
	 * @param array $extracted_data An array containing the extracted data.
	 */
	public function build_post($url, $callback = null) {
		if (false === is_string($url) || empty($url) || false === filter_var($_POST['eio_extraction_url'], FILTER_VALIDATE_URL)) {
			throw new BadFunctionCallException('You must provide a valid URL.');
		}
		
		if (false === is_null($callback) && (false === is_object($callback) || 'Closure' !== get_class($callback))) {
			throw new BadFunctionCallException('You must provide a valid closure or a null callback.');
		}
		
		$extracted_data = $this->extract_data($url);
		
		if (is_null($extracted_data) || false === is_array($extracted_data)) {
			if (false === is_null($callback)) {
				$callback(self::EXTRACTION_FAILED, null);
			}
			
			return false;
		}
		
		if (1 > count($extracted_data['results'])) {
			if (false === is_null($callback)) {
				$callback(self::EXTRACTED_DATA_NULL, null);
			}
			
			return false;
		}
		
		foreach ($extracted_data['results'] as $result) {
			$post_id = wp_insert_post(array(
				'post_status' => 'draft',
				'post_title' => __('Extractor IO - Currently Importing', 'extractor-io'),
				'post_content' => sprintf(
					__('This post is currently being imported by the Extractor IO plugin. It should be finished shortly. You can safely delete this post if Extractor IO failed to extract data from:<br /><strong>%s</strong>', 'extractor-io'),
					$url
				)
			));
			
			if (0 === $post_id) {
				if (false === is_null($callback)) {
					$callback(self::POST_INSERT_FAILED, null);
				}
			
				return false;
			}
			
			$post_data = array(
				'ID' => $post_id,
				'post_status' => 'draft',
				'post_title' => '',
				'post_content' => ''
			);
			
			
			
			foreach ($result as $key => $value) {
				$type = null;
				
				foreach ($extracted_data['outputProperties'] as $property) {
					if ($property['name'] === $key) {
						$type = $property['type'];
						break;
					}
				}
				
				if (false === is_array($value)) {
					$value = array($value);
				}
				
				switch ($this->mapping[$key]) {
					case 'post_title':
						if ('STRING' === $type) {
							foreach ($value as $title) {
								if (false === empty($post_data['post_title'])) {
									$post_data['post_title'] .= ', ';
								}
								
								$post_data['post_title'] .= $title;
							}
						}
						break;
					
					case 'post_content':
						if ('STRING' === $type) {
							foreach ($value as $content) {
								if (false === empty($post_data['post_content'])) {
									$post_data['post_content'] .= "\n";
								}
								
								$post_data['post_content'] .= $content;
							}
						} else if ('IMAGE' === $type) {
							$alts = null;
							
							if (array_key_exists('image/_alt', $result) && $result['image/_alt']) {
								$alts = is_array($result['image/_alt']) ? $result['image/_alt'] : array($result['image/_alt']);
							}
							
							foreach ($value as $index => $image) {
								$img = media_sideload_image($image, $post_id, ($alts ? $alts[$index] : null));
								
								if (is_string($img)) {
									if (false === empty($post_data['post_content'])) {
										$post_data['post_content'] .= "\n";
									}
									
									$post_data['post_content'] .= $img;
								}
							}
						}
						break;
					
					case 'import_only':
						if ('IMAGE' === $type) {
							$alts = null;
							
							if (array_key_exists('image/_alt', $result) && $result['image/_alt']) {
								$alts = is_array($result['image/_alt']) ? $result['image/_alt'] : array($result['image/_alt']);
							}
							
							foreach ($value as $index => $image) {
								media_sideload_image($image, $post_id, ($alts ? $alts[$index] : null));
							}
						}
						break;
				}
			}
			
			if (0 === wp_update_post($post_data)) {
				wp_delete_post($post_id, true);
				
				if (false === is_null($callback)) {
					$callback(self::POST_INSERT_FAILED, null);
				}
			
				return false;
			}
			
			if (false === is_null($callback)) {
				$callback(self::POST_EXTRACTED, $post_id);
			}
		}
		
		return true;
	}
}

endif;

?>