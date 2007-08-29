<?php
//Include the ActiveRecord model
require_once 'Location.php';
/**
 * 
 *
 * @package Omeka
 * 
 **/
class Geolocation extends Kea_Plugin
{	
	protected $metaInfo = array(
			'description'=>'Uses the Google Maps API to allow Items to be associated with a geographical location.',
			'author'=>'Center for History & New Media');
	
	public function definition() {
		$this->hasConfig('Default Latitude', 'The default latitude for the map.', 50);
		$this->hasConfig('Default Longitude', 'The default longitude for the map.', 70);
		$this->hasConfig('Default ZoomLevel', 'The default zoom level for the map.', 5);
		$this->hasConfig('Google Maps API Key', 'The API key (plugin will not work properly without this).');
	}
	
	/**
	 * Create the `locations` table that will store all the map locations
	 *
	 * @return void
	 **/
	public function customInstall()
	{
		//Create the locations table
		$this->getDbConn()->execute("CREATE TABLE `locations` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
			`item_id` BIGINT UNSIGNED NOT NULL ,
			`latitude` DOUBLE NOT NULL ,
			`longitude` DOUBLE NOT NULL ,
			`zipcode` INT NOT NULL ,
			`zoom_level` INT NOT NULL ,
			`map_type` VARCHAR( 255 ) NOT NULL ,
			`address` TEXT NOT NULL ,
			INDEX ( `item_id` )
			) ENGINE = MYISAM ;");
	}

	/**
	 * Let the plugin know that it should throw some hooks into the items browsing to 
	 * filter out items based whether or not they have locations associated with them
	 *
	 * @return void
	 **/
	public function setMapDisplay($bool=true)
	{
		$this->displayMap = true;
	}
	
	/**
	 * Plugin must be able to add SQL to the master query that pulls down a list of items
	 *
	 * @return void
	 **/
	public function filterBrowse($select, $recordType)
	{
		if( ($recordType == 'Item') and $this->displayMap) {
			
			//INNER JOIN the locations table
			$select->innerJoin(array('Location', 'l'), 'l.item_id = i.id');
		}
	}
	
	//Generic Omeka hook that is called whenever a form is submitted
	public function onCommitForm($record, $post)
	{
		switch (get_class($record)) {
			case 'Item':
				return $this->commitMapForm($record, $post);				
				break;
			
			default:
				break;
		}
		
	}
	
	protected function commitMapForm($item, $post) {
		$geo_post = $post['geolocation'][0];
		
		//Find the ActiveRecord location object
		$location = Doctrine_Manager::getInstance()->getTable('Location')->findLocationByItem($item);
				
		//If we have filled out info for the geolocation, then submit to the db
		if(!empty($geo_post) and !empty($geo_post['latitude']) and !empty($geo_post['longitude'])) {
				
			if(!$location) {
				$location = new Location;
				$location->item_id = $item->id;						
			}
			
			if( $location->commitForm($geo_post) ) {
				return true;
			}
		}		
		//If the form is empty, then we want to delete whatever location is currently stored
		else {
			if($location) {
				$location->delete();
			}
		}
	}
	
	/**
	 * Append map data to relevant theme pages
	 *
	 * @return void
	 **/
	public function appendToPage($page, $options) 
	{
		//This should pull in the $items var
		extract($options);
		
		switch ($page) {
			case 'items/form':
				map_form($item);
				break;
			
			case 'items/show':
				map_for_item($item);
				break;
				
			default:
				# code...
				break;
		}
	}
	
} // END class Geolocation


/**
 * Return a multidimensional array of location info
 *
 * @param array|int $item_id
 * @return array
 **/
function get_location_for_item($item_id)
{
	$select = new Kea_Select;
	$select->from(array('Location', 'l'), 'l.*');
	
	$item_id = ($item_id instanceof Item) ? $item_id->id : $item_id;
	
	//Create a WHERE condition that will pull down all the location info
	if(count($item_id) > 1 or ($item_id instanceof Doctrine_Collection)) {
		
		//Loop through a collection of ActiveRecord items
		if($item_id instanceof Doctrine_Collection) {
			foreach ($item_id as $item) {
				$select->orWhere('l.item_id = ?', $item->id);
			}
		}
		//Loop through an array of item IDs
		else {
			foreach ($item_id as $id) {
				$select->orWhere('l.item_id = ?', $id);
			}			
		}
		

	}else {
		$select->where('l.item_id = ?', $item_id);
	}
		
	//Fetch the data
	$array = $select->fetchAll();
	
	
	//Now process into an array where the key is the item_id		
	$locations = array();	
	foreach ($array as $k => $row) {
		$locations[$row['item_id']] = $row;
	}	
			
	return $locations;
}

	/**
	 *  Possible options include:
	 *		form = 'geolocation'  (provides the prefix for form elements that should catch the map coordinates)
	 *		 
	 * 
	 **/
	function google_map($divName = 'map', $options = array()) {
		echo "<div id=\"$divName\"></div>";
		//Load this junk in from the plugin config

		$plugin = Zend::Registry( 'Geolocation' );
		$options['default']['latitude'] = $plugin->getConfig('Default Latitude');
		$options['default']['longitude'] = $plugin->getConfig('Default Longitude');
		$options['default']['zoomLevel'] = $plugin->getConfig('Default ZoomLevel');
		
		//Load the Key into the plugin config
		//$options['api_key'] = $plugin->getConfig('Google Maps API Key');

		//The request parameters get put into the map options
		$params = array();
		if(!isset($options['params'])) {
			$params = $_GET;
		}else {
			$params = array_merge($options['params'], $_GET);
		}
		
		//Append the 'rest' parameter to signify that we want to return XML		
		$params['output'] = 'rest';
		$options['params'] = $params;	

		require_once 'Zend/Json.php';
		$options = Zend_Json::encode($options);
		echo "<script>var ${divName}Omeka = new OmekaMap('$divName', $options);</script>";
	}
	
	function map_for_item($item, $width=200, $height=200) {		
		google_map('item_map' . $item->id, 
			array(
				'uri'=>uri('map/show'),
				'params'=>array('id'=>$item->id), 
				'type'=>'show', 
				'width'=>$width,
				'height'=>$height));
	}
	
	function map_form($item, $width=400, $height=400) { ?>
		<?php 
			$loc = array_pop(get_location_for_item($item));
			
			$usePost = !empty($_POST);
			
			if($usePost) {
				$lng = (int) @$_POST['geolocation'][0]['longitude'];
				$lat =  (int) @$_POST['geolocation'][0]['latitude'];
				$zoom = (int) @$_POST['geolocation'][0]['zoom_level'];
				$addr = @$_POST['geolocation'][0]['address'];
			}else {
				$lng = (int) $loc['longitude'];
				$lat = (int) $loc['latitude'];
				$zoom = (int) $loc['zoom_level'];
				$addr = $loc['address'];
			}
		?>
		
		<fieldset id="location_form">
			<input type="hidden" name="geolocation[0][latitude]" value="<?php echo $lat; ?>" />
			<input type="hidden" name="geolocation[0][longitude]" value="<?php echo $lng; ?>" />
			<input type="hidden" name="geolocation[0][zoom_level]" value="<?php echo $zoom; ?>" />
			<input type="hidden" name="geolocation[0][map_type]" value="Google Maps V2" />
			
			<label>Find Your location via address:</label>
			<input type="text" name="geolocation[0][address]" id="geolocation_address" size="60" value="<?php echo $addr; ?>" />
			<input type="submit" name="find_location_by_address" id="find_location_by_address" value="Find By Address" />
		</fieldset>
		
		<?php 
		$options = array(
			'form'=>'geolocation');
		
		if($lng and $lat) {
			//B/c of changes in map via POST, we should always pass the form the map parameters manually
			$options['point'] = array('lng'=>$lng, 'lat'=>$lat, 'zoom'=>$zoom);			
		}
		
		$options['type'] = 'form';
		
		google_map($width, $height, 'item_form', $options);
	 } 
?>
