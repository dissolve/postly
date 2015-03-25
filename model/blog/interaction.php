<?php
class ModelBlogInteraction extends Model {

    public function addWebmention($data, $webmention_id, $comment_data, $post_id = null){
        if(isset($comment_data['published']) && !empty($comment_data['published'])){
            // do our best to conver to local time
            date_default_timezone_set(LOCALTIMEZONE);
            $date = new DateTime($comment_data['published']);
            $now = new DateTime;
            $tz = $now->getTimezone();
            $date->setTimezone($tz);
            $comment_data['published'] = $date->format('Y-m-d H:i:s')."\n";
        }


        if($post_id || (isset($data['year']) && isset($data['month']) && isset($data['day']) && isset($data['daycount']))) {
//TODO: this can likely be cleaned up to reducde dependance on model_blog_post
            $post = null;
            if($post_id){
                $post= $this->model_blog_post->getPost($post_id);
            } else {
                $post = $this->model_blog_post->getPostByDayCount($data['year'],$data['month'], $data['day'], $data['daycount']);
            }

	    $interaction_type = 'mention';

            switch($comment_data['type']) {
            case 'like':
		$interaction_type = 'like';
                break;
            case 'reply':
		$interaction_type = 'reply';
                break;
            case 'repost':
		$interaction_type = 'repost';
                break;
            case 'tag':
		$interaction_type = 'tag';
                break;
            case 'rsvp':
		$interaction_type = 'rsvp';
                break;
            //case 'mention':
            //default:
	    }


	    $this->db->query("INSERT INTO ". DATABASE.".interactions SET source_url = '".$comment_data['url']."'".
		((isset($comment_data['author']) && isset($comment_data['author']['name']) && !empty($comment_data['author']['name']))? ", author_name='".$comment_data['author']['name']."'" : "") .
		((isset($comment_data['author']) && isset($comment_data['author']['url']) && !empty($comment_data['author']['url']))? ", author_url='".$comment_data['author']['url']."'" : "") .
		((isset($comment_data['author']) && isset($comment_data['author']['photo']) && !empty($comment_data['author']['photo']))? ", author_image='".$comment_data['author']['photo']."'" : "") .
		((isset($comment_data['tag-of']) && !empty($comment_data['tag-of']))? ", tag_of='".$comment_data['tag-of']."'" : "") .
                ((isset($comment_data['text'])  && !empty($comment_data['text']))? ", body='".$this->db->escape($comment_data['text'])."'" : "") .
                ((isset($comment_data['name'])  && !empty($comment_data['name']))? ", source_name='".$this->db->escape($comment_data['name'])."'" : "") .
                ((isset($comment_data['published'])  && !empty($comment_data['published']))? ", `timestamp`='".$this->db->escape($comment_data['published'])."'" : ", `timestamp`=NOW()") .
		", webmention_id='".$webmention_id."'".
		", interaction_type='".$interaction_type."'".
                ", post_id = ".(int)$post['post_id'].
		", parse_timestamp = NOW()".
		", approved=1".
		"");
	    $interaction_id = $this->db->getLastId();
	    $this->db->query("UPDATE ". DATABASE.".webmentions SET webmention_status_code = '200', webmention_status = 'OK' WHERE webmention_id = ". (int)$webmention_id);
	    $this->cache->delete('interactions');


        } else {
            throw new Exception('Cannot look up record');
            //throwing an exception will go back to calling script and run the generic add
        }
    }

    public function editWebmention($data, $webmention_id, $comment_data, $post_id = null){

        $query = $this->db->query("SELECT webmention_id, interactions.* FROM ". DATABASE.".webmentions JOIN ".DATABASE.".interactions USING(webmention_id) WHERE webmention_id = ".(int)$webmention_id." LIMIT 1");
        $webmention = $query->row;
	if($webmention_id){
	    $this->db->query("UPDATE ".DATABASE.".interactions SET deleted=1 WHERE webmention_id = ".(int)$webmention_id);
	    $this->addWebmention($data, $webmention_id, $comment_data, $post_id);
	    $this->db->query("UPDATE ".DATABASE.".webmentions SET webmention_status='Updated' WHERE webmention_id = ".(int)$webmention_id);
	}
	
    }


    public function getTagsForPost($post_id, $limit=100, $skip=0) {
        $data = $this->cache->get('tags.post.'.$post_id.'.'. $skip . '.'.  $limit);
        if(!$data){
		//TODO
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".tags WHERE post_id = ".(int)$post_id." ORDER BY like_id DESC LIMIT ". (int)$skip . ", " . (int)$limit);
            $data = $query->rows;
            $this->cache->set('tags.post.'.$post_id.'.'. $skip . '.' .$limit, $data);
        }
	return $data;
    }


//below this has been upgraded to Interactions methods


    public function getGenericLikes($limit=100, $skip=0) {
        $data = $this->cache->get('interactions.like.generic.'. $skip . '.'.  $limit);
        if(!$data){
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".interactions WHERE interaction_type='like' AND post_id IS NULL AND deleted=0 ORDER BY timestamp ASC LIMIT ". (int)$skip . ", " . (int)$limit);
            $data = $query->rows;
            $this->cache->set('interactions.like.generic.'. $skip . '.' .$limit, $data);
        }
	return $data;
    }

    public function getGenericLikeCount() {
        $data = $this->cache->get('interactions.like.generic.count');
        if(!$data){
            $query = $this->db->query("SELECT COUNT(*) AS total FROM " . DATABASE . ".interactions WHERE interaction_type='like' AND post_id IS NULL AND deleted=0");
            $data = $query->row['total'];
            $this->cache->set('interactions.like.generic.count', $data);
        }
	return $data;
    }

    public function getLikesForPost($post_id, $limit=100, $skip=0) {
        $data = $this->cache->get('interactions.like.post.'.$post_id.'.'. $skip . '.'.  $limit);
        if(!$data){
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".interactions WHERE interaction_type='like' AND post_id = ".(int)$post_id." AND deleted=0 ORDER BY timestamp ASC LIMIT ". (int)$skip . ", " . (int)$limit);
            $data = $query->rows;
            $this->cache->set('interactions.like.post.'.$post_id.'.'. $skip . '.' .$limit, $data);
        }
	return $data;
    }

    public function getLikeCountForPost($post_id) {
        $data = $this->cache->get('interatcions.like.post.count.'.$post_id);
        if(!$data){
            $query = $this->db->query("SELECT COUNT(*) AS total FROM " . DATABASE . ".interactions WHERE interaction_type='like' AND post_id = ".(int)$post_id." AND deleted=0");
            $data = $query->row['total'];
            $this->cache->set('interactions.like.post.count.'.$post_id, $data);
        }
	return $data;
    }

    public function getGenericMentions($limit=100, $skip=0) {
        $data = $this->cache->get('interactions.mentions.generic.'. $skip . '.'.  $limit);
        if(!$data){
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".interactions WHERE interaction_type='mention' AND post_id IS NULL AND deleted=0 ORDER BY timestamp DESC LIMIT ". (int)$skip . ", " . (int)$limit);
            $data = $query->rows;
            $this->cache->set('interactions.mentions.generic.'. $skip . '.' .$limit, $data);
        }
	return $data;
    }

    public function getGenericMentionCount() {
        $data = $this->cache->get('interactions.mentions.generic.count');
        if(!$data){
            $query = $this->db->query("SELECT COUNT(*) AS total FROM " . DATABASE . ".interactions WHERE interaction_type='mention' AND post_id IS NULL AND deleted=0");
            $data = $query->row['total'];
            $this->cache->set('interactions.mentions.generic.count', $data);
        }
	return $data;
    }

    public function getMentionsForPost($post_id, $limit=100, $skip=0) {
        $data = $this->cache->get('interactions.mentions.post.'.$post_id.'.'. $skip . '.'.  $limit);
        if(!$data){
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".interactions WHERE interaction_type='mention' AND post_id = ".(int)$post_id." AND deleted=0 ORDER BY timestamp DESC LIMIT ". (int)$skip . ", " . (int)$limit);
            $data = $query->rows;
            $this->cache->set('interactions.mentions.post.'.$post_id.'.'. $skip . '.' .$limit, $data);
        }
	return $data;
    }

    public function getMentionCountForPost($post_id) {
        $data = $this->cache->get('interatcions.mentions.post.count.'.$post_id);
        if(!$data){
            $query = $this->db->query("SELECT COUNT(*) AS total FROM " . DATABASE . ".interactions WHERE interaction_type='mention' AND post_id = ".(int)$post_id." AND deleted=0");
            $data = $query->row['total'];
            $this->cache->set('interactions.mentions.post.count.'.$post_id, $data);
        }
	return $data;
    }


    public function getCommentsForPost($post_id, $limit=100, $skip=0) {
        $data = $this->cache->get('interactions.comment.post.'.$post_id.'.'. $skip . '.'.  $limit);
        if(!$data){
            $query = $this->db->query("SELECT interactions.*, webmentions.vouch_url FROM " . DATABASE . ".interactions JOIN " . DATABASE . ".webmentions USING(webmention_id) WHERE interaction_type='reply' AND post_id = ".(int)$post_id." AND deleted=0 ORDER BY timestamp DESC LIMIT ". (int)$skip . ", " . (int)$limit);
            $data = $query->rows;
            $this->cache->set('interactions.comment.post.'.$post_id.'.'. $skip . '.' .$limit, $data);
        }
	return $data;
    }

    public function getCommentCountForPost($post_id) {
        $data = $this->cache->get('interatcions.reply.post.count.'.$post_id);
        if(!$data){
            $query = $this->db->query("SELECT COUNT(*) AS total FROM " . DATABASE . ".interactions WHERE interaction_type='reply' AND post_id = ".(int)$post_id." AND deleted=0");
            $data = $query->row['total'];
            $this->cache->set('interactions.reply.post.count.'.$post_id, $data);
        }
	return $data;
    }






    public function getGenericLikes($type, $limit=100, $skip=0) {
        $data = $this->cache->get('interactions.'.$type.'.generic.'. $skip . '.'.  $limit);
        if(!$data){
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".interactions WHERE interaction_type='".$type."' AND post_id IS NULL AND deleted=0 ORDER BY timestamp ASC LIMIT ". (int)$skip . ", " . (int)$limit);
            $data = $query->rows;
            $this->cache->set('interactions.'.$type.'.generic.'. $skip . '.' .$limit, $data);
        }
	return $data;
    }

    public function getGenericLikeCount($type) {
        $data = $this->cache->get('interactions.'.$type.'.generic.count');
        if(!$data){
            $query = $this->db->query("SELECT COUNT(*) AS total FROM " . DATABASE . ".interactions WHERE interaction_type='".$type."' AND post_id IS NULL AND deleted=0");
            $data = $query->row['total'];
            $this->cache->set('interactions.'.$type.'.generic.count', $data);
        }
	return $data;
    }

    public function getInteractionsForPost($post_id, $type, $limit=100, $skip=0) {
	//correct my vocabulary
	if($type == 'comment'){
	    $type = 'reply';
	}

        $data = $this->cache->get('interactions.'.$type.'.post.'.$post_id.'.'. $skip . '.'.  $limit);
        if(!$data){
            $query = $this->db->query("SELECT interactions.*, webmentions.vouch_url FROM " . DATABASE . ".interactions JOIN " . DATABASE . ".webmentions USING(webmention_id) WHERE interaction_type='".$type."' AND post_id = ".(int)$post_id." AND deleted=0 ORDER BY timestamp DESC LIMIT ". (int)$skip . ", " . (int)$limit);
            $data = $query->rows;
            $this->cache->set('interactions.'.$type.'.post.'.$post_id.'.'. $skip . '.' .$limit, $data);
        }
	return $data;
    }

    public function getInteractionCountForPost($post_id, $type) {
        $data = $this->cache->get('interatcions.'.$type.'.post.count.'.$post_id);
        if(!$data){
            $query = $this->db->query("SELECT COUNT(*) AS total FROM " . DATABASE . ".interactions WHERE interaction_type='".$type."' AND post_id = ".(int)$post_id." AND deleted=0");
            $data = $query->row['total'];
            $this->cache->set('interactions.'.$type.'.post.count.'.$post_id, $data);
        }
	return $data;
    }


}
