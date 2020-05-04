<?php
/*
	functions start with push_, pull_, get_, do_ or is_
	push_ is to save to database
	pull_ is to pull from database, returns 1 or 0, saves the output array in $last_data
	get_ is to get usable values from functions
	do_ is for action that doesn't have a database push or pull
	is_ is for a yes/no answer
*/

class dash {  

	public static $last_error = null; //array of error messages
	public static $last_info = null; //array of info messages
	public static $last_data = null; //array of data to be sent for display
	public static $last_redirect = null; //redirection url

	function __construct () {
		
	}

	function get_last_error () {
		if (count(dash::$last_error)) {
			$op=implode('<br>', dash::$last_error);
			dash::$last_error=array();
			return $op;
		}
		else
			return '';
	}

	function get_last_info () {
		if (count(dash::$last_info)) {
			$op=implode('<br>', dash::$last_info);
			dash::$last_info=array();
			return $op;
		}
		else
			return '';
	}

	function get_last_data () {
		$arr=dash::$last_data;
		dash::$last_data=array();
		return $arr;
	}

	function get_last_redirect () {
		$r=dash::$last_redirect;
		dash::$last_redirect='';
		return $r;
	}

	function get_next_id () {
		global $sql;
		$q=$sql->executeSQL("SELECT `id` FROM `data` WHERE 1 ORDER BY `id` DESC LIMIT 1");
		return ($q[0]['id']+1);
	}

	function do_delete ($post=array()) {
		global $sql;
		$q=$sql->executeSQL("DELETE FROM `data` WHERE `id`='".$post['id']."'");
		dash::$last_redirect='/admin/list?type='.$post['type'];
		return 1;
	}

	function push_content ($post) {
		global $sql, $types;
		$updated_on=time();
		$posttype=$post['type'];

		$i=0;
		foreach ($types[$posttype]['modules'] as $module) {
			if ($module['input_primary'] && (!$module['restrict_id_max'] || $post['id']<=$module['restrict_id_max']) && (!$module['restrict_id_min'] || $post['id']>=$module['restrict_id_min'])) {
				$title_id=$i;
				$title_slug=$module['input_slug'].(is_array($module['input_lang'])?'_'.$module['input_lang'][0]['slug']:'');
				$title_primary=$module['input_primary'];
				$title_unique=$module['input_unique'];
				break;
			}
			$i++;
		}

		if ($title_unique) {
			$q=$sql->executeSQL("SELECT `id` FROM `data` WHERE `content`->'$.type'='".$post['type']."' && `content`->'$.".$title_slug."'='".$post[$title_slug]."'");
			if ($q[0]['id'] && $post['id']!=$q[0]['id']) {
				dash::$last_error[]='Either the title is left empty or the same title already exists in '.$types[$posttype]['plural'];
				return 0;
			}
		}

		if (!trim($post['slug']) || $post['slug_update']) {
			$post['slug']=dash::do_slugify($post[$title_slug], $title_unique);
		}

		if (!trim($post['id'])) {
			$sql->executeSQL("INSERT INTO `data` (`created_on`, `user_id`) VALUES ('$updated_on', '1')");
			$post['id']=$sql->lastInsertID();
		}

		if ($post['wp_import']) {
			$sql->executeSQL("INSERT INTO `data` (`id`, `created_on`, `user_id`) VALUES ('".$post['id']."', '$updated_on', '1')");
		}

		$sql->executeSQL("UPDATE `data` SET `content`='".mysqli_real_escape_string($sql->databaseLink, json_encode($post))."', `updated_on`='$updated_on' WHERE `id`='".$post['id']."'");
		$id=$post['id'];

		dash::$last_info[]='Content saved.';
		dash::$last_data[]=array('updated_on'=>$updated_on, 'id'=>$id, 'slug'=>$post['slug'], 'url'=>BASE_URL.'/'.$post['type'].'/'.$post['slug']);
		return 1;
	}

	function get_content_meta ($id, $meta_key) {
		global $sql;
		$q=$sql->executeSQL("SELECT * FROM `data` WHERE `id`='$id'");
		$or=json_decode($q[0]['content'], true);
		return $or[$meta_key];
	}

	function get_content ($val) {
		global $sql;
		$or=array();
		if (is_numeric($val))
			$q=$sql->executeSQL("SELECT * FROM `data` WHERE `id`='$val'");
		else 
			$q=$sql->executeSQL("SELECT * FROM `data` WHERE `content`->'$.slug'='".$val['slug']."' && `content`->'$.type'='".$val['type']."'");
		$or=array_merge(json_decode($q[0]['content'], true), $q[0]);
		return $or;
	}

	function get_all_ids ($type, $priority_field='id', $priority_order='DESC', $limit='') {
		global $sql;
		if ($priority_field=='id')
			$priority="`".$priority_field."` ".$priority_order;
		else
			$priority="`content`->'$.".$priority_field."' IS NULL, `content`->'$.".$priority_field."' ".$priority_order.", `id` DESC";
		return $sql->executeSQL("SELECT `id` FROM `data` WHERE `content`->'$.type'='$type' ORDER BY ".$priority.($limit?" LIMIT ".$limit:""));
	}

	function get_date_ids ($publishing_date) {
		global $sql;
		return $sql->executeSQL("SELECT `id` FROM `data` WHERE `content`->'$.publishing_date'='$publishing_date'");
	}

	function do_slugify ($string, $input_itself_is_unique=0) {
		$slug = strtolower(trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', ($string?$string:'untitled')))).($input_itself_is_unique?'':'-'.uniqid());
		return $slug;
	}
	
	function do_unslugify ($url_part) {
		return strtolower(trim(urlencode($url_part)));
	}

	function get_types ($json_path) {
		$types=json_decode(file_get_contents($json_path), true);
		foreach ($types as $key=>$type) {
			if ($type['type']=='content' && !in_array('content_privacy', array_column($types[$key]['modules'], 'input_slug'))) {
				$content_privacy_json='{
			        "input_slug": "content_privacy",
			        "input_placeholder": "Content privacy",
			        "input_type": "select",
			        "input_options": [
			          {"slug":"public", "title":"Public link"},
			          {"slug":"private", "title":"Private link"},
			          {"slug":"draft", "title":"Draft"}
			        ],
			        "list_field": true,
			        "input_unique": false
			      }';
				$types[$key]['modules'][]=json_decode($content_privacy_json, true);
		  }
		}
		return $types;
	}

	function push_wp_posts ($type='story', $wp_table_name='wp_posts', $max_records=0) {
		global $sql;
		$i=0;
		$q=$sql->executeSQL("SELECT * FROM `".$wp_table_name."` WHERE `post_status` LIKE 'publish' AND `post_parent` = 0 AND `post_type` LIKE 'post'");
		foreach ($q as $r) {
			$post=array();
			$post['wp_import']=1;
		    $post['id']=$r['ID'];
		    $post['type']=$type;
		    $post['title']=$r['post_title'];
		    $post['body']=$r['post_content'];
		    $post['slug']=$r['post_name'];
		    $post['content_privacy']='public';
		    $post['publishing_date']=substr($r['post_date'], 0, 10);
		    $cv=$sql->executeSQL("SELECT `guid` FROM `".$wp_table_name."` WHERE `post_parent` != 0 AND `guid` LIKE '%wp-content/uploads%' AND `post_type` LIKE 'attachment' AND `post_status` LIKE 'inherit' AND `guid` != '' AND `post_parent`='".$r['ID']."' ORDER BY `ID` DESC");
		    $post['cover_media']=$cv[0]['guid'];
		    dash::push_content($post);

		    $i++;
			if ($max_records && $i>=$max_records)
				break;
		}
	}
}
?>