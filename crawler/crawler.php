<?php
/* Crawler - A website indexer.
 * -Can log into a website, 
 * -Crawls a website,
 * -Saves data to database
 * -Saves Page title, URL, Body text, and depth level
 * @author Robert Parham <adelphia at protonmail dot ch>
 * @license Apache 2.0 Lic.
 */

/* Crawler class
 * The base class that exposes all functionality
 */
class Crawler {
	
	/* Holds the configuration options
	 * Includes MySQL details, website login details, etc.
	 * See: config.php
	 */
	private $config;
	
	
	/* Output the output in realtime or wait till it's called for
	 */
	public $dumpOutput = false;
	
	
	/* Output the output in HTML or plaintext
	 */
	public $formattedOutput = false;
	
	
	/* Holds output
	 */
	public $output = array();
	
	
	/* Holds the instance of the class
	 */
	private static $instance;
	
	
	/* An array of rows of URLs to be crawled next
	 */
	private $queue = array();
	
	
	/* Returns the Crawler object
	 */
	public static function getInstance(){
        if (empty(self::$instance)){
			$class = __CLASS__;
            self::$instance = new $class;
        }
        return self::$instance;
    }
	
	
	/**
	 * Constructor - sets configuration
	 * @global type $CrawlerConfig
	 */
	private function __construct() {
		
		// Set the config property
		global $CrawlerConfig;
		$this->config = $CrawlerConfig;
		
		// Start a session
		if( !empty($this->config['AUTH']) && 
			!empty($this->config['LOGIN_ENDPOINT'])
		) CrawlerRequest::startSession();
		
		// Make sure the table exists,
		// or create it if it doesn't
		CrawlerPDO::checkTable();
		
		// Set queue of URLs to crawl
		$this->queue = CrawlerPDO::getNextURLs();
		
		return;
	}
	
	/* Adds output to the output array
	 * @param string $str - The text to add
	 */
	public function addOutput($str){
		
		// Add output to output array
		array_push($this->output, $str);
		
		// Dump the output if that option is set
		if($this->dumpOutput){
			echo $this->formattedOutput ? "<pre style='display:block;'>$str</pre>" : "$str";
		}
	}
	
	
	/* Returns (and echos, if configured) the ouput generated by the crawler
	 */
	public function getOutput(){
		
		// Contains the output to be returned/echoed
		$ret = array();
		
		// Loop through the output
		foreach($this->output as $o){
			
			// Formatted or plaintext?
			$o = $this->formattedOutput ? "<pre style='display:block;'>$o</pre>" : "$o";
			
			// Add it to $ret
			array_push($ret, $o);
		}
		
		// Implode into a string
		$ret = implode("\n",$ret);
		
		// Echo the ouput if configured
		if($this->dumpOutput) echo $ret;
		
		// Return the ouput
		return $ret;
	}
	
	
	/* Starts the process of crawling the URLs in the queue
	 * @param int $max_depth - if zero, crawls until it can't find any more links
	 *   otherwise, $depth determines the number of times the queue will refresh
	 * @param int $current_depth - Not to be set! This paramter is only used for
	 *   recursion purposes. It counts the number of times the queue has been 
	 *   refreshed.
	 */
	public function crawl($max_depth=0, $current_depth=0){
		
		// Begin the loop through each URL row
		foreach($this->queue as $k=>$page){
			
			// Get the depth of the current item
			$depth = CrawlerPDO::getDepthOfUrl($page['url']);
			
			// Get the page body
			$body = CrawlerRequest::request($page['url']);
			
			// Get an new instance of our HTML parser
			$parser = new CrawlerParser($body, $page['url']);
			
			// Download images if configured
			if($this->config['SAVE_IMAGES'] === true){
				$images = $parser->getImages();
				foreach($images as $image){
					
					// Check download size
					if(!empty($this->config['MIN_IMAGE_SIZE'])){
						$size = CrawlerRequest::getFileSize($image);
						if($size < $this->config['MIN_IMAGE_SIZE']) continue;
					}
					
					$ctype = CrawlerRequest::getContentType($image);
					
					// skip files that don't have explicit contetn type
					if(strpos($ctype, "image/") === false) continue;
					
					// get extention
					$ext = explode("/", $ctype);
					$ext = $ext[1];
					
					// save the file
					$fn = preg_replace("/[^A-Za-z0-9 ]/", '', $image);
					$filename = realpath(dirname(__FILE__))."/media/cj_$fn.$ext";
					
					// Get the image if we don't already have it
					if(!file_exists($filename))
						CrawlerRequest::request($image, $params = array(), $filename);
				}
			}
			
			/* Crawl result contains two things we need...
			 *   - 1) Info needed to update the current $page in the $queue, and
			 *   - 2) A new list of links
			 *  Each of the new links will be checked to see if they exist in 
			 *  the table yet, if they do they will be updated with referrer 
			 *  information, etc. If the new link doesn't exist it will be added
			 *  to the table to be crawled next time the queue is updated.
			 */
			$crawlResult = array(
				"body" => $parser->getPlaintext(),
				"links" => $parser->getLinks(),
				"depth" => ($depth+1)
			);
			
			// Loop thru and check and update or insert each new link
			foreach($crawlResult['links'] as $link){
				
				
				
				// If the URL was already discovered
				if(CrawlerPDO::URLDiscovered($link['url'])){
					CrawlerPDO::updateRow(array(
						"title" => $link['title'],
						"url" => $link['url'],
						"linked_from" => CrawlerPDO::getURLID($page['url']),
						"depth" => $crawlResult['depth']
					));
				}
				
				// If the URL was not discovered yet
				else{
					CrawlerPDO::insertRow(array(
						"url" => $link['url'],
						"title" => $link['title'],
						"linked_from" => CrawlerPDO::getURLID($page['url']),
						"depth" => $crawlResult['depth']
					));
				}
				
			}
			
			// Update the record for the page we just crawled
			CrawlerPDO::updateRow(array(
				"title" => $page['title'],
				"url" => $page['url'],
				"body" => $crawlResult['body'],
				"depth" => $depth,
				"crawled" => 1
			));
			
			// Add some output
			$this->addOutput("Found ".count($crawlResult['links'])." links on {$page['url']}.");
			
			// pop this item off the queue
			unset($this->queue[$k]);
		}
		
		// Queue is empty!
		// Incremenent the depth counter
		$current_depth++;
		
		// Refresh the queue and keep going?
		if($max_depth == 0 || $max_depth > $current_depth){
			$this->queue = CrawlerPDO::getNextURLs();
			if(!empty($this->queue)) $this->crawl($max_depth, $current_depth);
		}
	}
}