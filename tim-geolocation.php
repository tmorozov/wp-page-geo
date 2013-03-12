<?php
/*
Plugin Name: Tim Geo Location
Description: This plugin allows to add geo information to page
Author: Timur Morozov
Version: 1.0
*/

add_action( 'add_meta_boxes', 'ats_geo_location_add_meta_box' );
add_action( 'save_post', 'ats_geo_location_save_postdata' );
add_action('admin_head-post-new.php', 'ats_geo_location_admin_head');
add_action('admin_head-post.php', 'ats_geo_location_admin_head');

function ats_geo_location_add_meta_box() {
	add_meta_box('ats_geo_location_sectionid', __( 'ATS Geolocation' ), 'ats_geo_location_inner_custom_box', 'page', 'side' );
}

function ats_geo_location_inner_custom_box() {
	?>
	<input type="hidden" id="geolocation_nonce" name="geolocation_nonce" value="<?php echo wp_create_nonce(plugin_basename(__FILE__) ); ?>" />
	<label class="screen-reader-text" for="geolocation-address">Address</label>
	<input type="text" id="geolocation-address" name="geolocation-address" class="newtag form-input-tip" size="25" autocomplete="off" value="" />
	<input id="geolocation-load" type="button" class="button geolocationadd" value="Load" tabindex="3" />
	<input type="hidden" id="geolocation-latitude" name="geolocation-latitude" />
	<input type="hidden" id="geolocation-longitude" name="geolocation-longitude" />
	<div id="geolocation-map" style="border:solid 1px #c6c6c6;width:265px;height:200px;margin-top:5px;"></div>
	<?php
}

function ats_geolocation_clean_coordinate($coordinate) {
	$pattern = '/^(\-)?(\d{1,3})\.(\d{1,15})/';
	preg_match($pattern, $coordinate, $matches);
	return $matches[0];
}

function ats_geolocation_reverse_geocode($latitude, $longitude) {
	$url = "http://maps.google.com/maps/api/geocode/json?latlng=".$latitude.",".$longitude."&sensor=false";
	$result = wp_remote_get($url);
	$json = json_decode($result['body']);
	foreach ($json->results as $result)
	{
		foreach($result->address_components as $addressPart) {
			if((in_array('locality', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$city = $addressPart->long_name;
	    	else if((in_array('administrative_area_level_1', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$state = $addressPart->long_name;
	    	else if((in_array('country', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$country = $addressPart->long_name;
		}
	}
	
	if(($city != '') && ($state != '') && ($country != ''))
		$address = $city.', '.$state.', '.$country;
	else if(($city != '') && ($state != ''))
		$address = $city.', '.$state;
	else if(($state != '') && ($country != ''))
		$address = $state.', '.$country;
	else if($country != '')
		$address = $country;
		
	return $address;
}

function ats_geo_location_save_postdata( $post_id ) {
	// Check authorization, permissions, autosave, etc
	if (!wp_verify_nonce($_POST['geolocation_nonce'], plugin_basename(__FILE__))) {
		return $post_id;
	}

	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return $post_id;
	}

	if('page' == $_POST['post_type'] ) {
		if(!current_user_can('edit_page', $post_id)) {
			return $post_id;
		}
	} else {
		if(!current_user_can('edit_post', $post_id)) {
			return $post_id;
		}
	}

	$latitude = ats_geolocation_clean_coordinate($_POST['geolocation-latitude']);
	$longitude = ats_geolocation_clean_coordinate($_POST['geolocation-longitude']);
	$address = ats_geolocation_reverse_geocode($latitude, $longitude);

	if((ats_geolocation_clean_coordinate($latitude) != '') && (ats_geolocation_clean_coordinate($longitude)) != '') {
		update_post_meta($post_id, 'geo_latitude', $latitude);
		update_post_meta($post_id, 'geo_longitude', $longitude);
	}

	return $post_id;
}

function ats_geo_location_admin_head() {
	global $post;
	$post_id = $post->ID;
	$post_type = $post->post_type;
	$zoom = 6; // country level
	
	wp_enqueue_script("jquery");
	
	?>
	<script type="text/javascript" src="http://www.google.com/jsapi"></script>
	<script type="text/javascript" src="http://maps.google.com/maps/api/js?sensor=true"></script>
	<script type="text/javascript">
	var $j = jQuery.noConflict();
	$j(function() {
		$j(document).ready(function() {
			var hasLocation = false;
			var center = new google.maps.LatLng(0.0,0.0);
			var postLatitude =  '<?php echo esc_js(get_post_meta($post_id, 'geo_latitude', true)); ?>';
			var postLongitude =  '<?php echo esc_js(get_post_meta($post_id, 'geo_longitude', true)); ?>';
			
			if((postLatitude != '') && (postLongitude != '')) {
				center = new google.maps.LatLng(postLatitude, postLongitude);
				hasLocation = true;
				$j("#geolocation-latitude").val(center.lat());
				$j("#geolocation-longitude").val(center.lng());
				reverseGeocode(center);
			}
				
			var myOptions = {
				'zoom': <?php echo $zoom; ?>,
				'center': center,
				'mapTypeId': google.maps.MapTypeId.ROADMAP
			};
				
			var map = new google.maps.Map(document.getElementById('geolocation-map'), myOptions);	
			var marker = new google.maps.Marker({
				position: center, 
				map: map, 
				title:'Post Location'
			});
			
			if((!hasLocation) && (google.loader.ClientLocation)) {
				center = new google.maps.LatLng(google.loader.ClientLocation.latitude, google.loader.ClientLocation.longitude);
				reverseGeocode(center);
			}
			
			google.maps.event.addListener(map, 'click', function(event) {
				placeMarker(event.latLng);
			});
			
			var currentAddress;
			var customAddress = false;
			$j("#geolocation-address").click(function(){
				currentAddress = $j(this).val();
				if(currentAddress != '')
					$j("#geolocation-address").val('');
			});
			
			$j("#geolocation-load").click(function(){
				if($j("#geolocation-address").val() != '') {
					customAddress = true;
					currentAddress = $j("#geolocation-address").val();
					geocode(currentAddress);
				}
			});
			
			$j("#geolocation-address").keyup(function(e) {
				if(e.keyCode == 13)
					$j("#geolocation-load").click();
			});
					
			function placeMarker(location) {
				marker.setPosition(location);
				map.setCenter(location);
				if((location.lat() != '') && (location.lng() != '')) {
					$j("#geolocation-latitude").val(location.lat());
					$j("#geolocation-longitude").val(location.lng());
				}
				
				if(!customAddress)
					reverseGeocode(location);
			}
			
			function geocode(address) {
				var geocoder = new google.maps.Geocoder();
				if (geocoder) {
					geocoder.geocode({"address": address}, function(results, status) {
						if (status == google.maps.GeocoderStatus.OK) {
							placeMarker(results[0].geometry.location);
							if(!hasLocation) {
								map.setZoom(16);
								hasLocation = true;
							}
						}
					});
				}
				$j("#geodata").html(latitude + ', ' + longitude);
			}
			
			function reverseGeocode(location) {
				var geocoder = new google.maps.Geocoder();
				if (!geocoder) {
					return;
				}
				geocoder.geocode({"latLng": location}, function(results, status) {
					if (status != google.maps.GeocoderStatus.OK) {
						return;
					}

					if(! results[1]) {
						return;
					}

					var address = results[1].formatted_address;
					if(address == "") {
						address = results[7].formatted_address;
					} else {
						placeMarker(location);
					}
				});
			}
		});
	});
	</script>
	<?php
}

?>