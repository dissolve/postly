<?php
require_once DIR_BASE . 'libraries/single-emoji-recognizer/src/emoji.php';
class ModelBlogInteraction extends Model {

    public function addWebmention($data, $webmention_id, $comment_data, $post_id = null)
    {
        //check if the source of this is already parsed
        $query = $this->db->query(
            "SELECT * " .
            " FROM " . DATABASE . ".interaction_syndication " .
            " WHERE url='" . $comment_data['url'] . "' " .
            " LIMIT 1"
        );
        if ( !empty($query->row) ) {
            $this->db->query(
                "UPDATE " . DATABASE . ".webmentions " .
                " SET status_code = '200', " .
                " status = 'duplicate' " .
                " WHERE id = " . (int)$webmention_id
            );
            return null;
        }

        if ( isset($comment_data['published']) && !empty($comment_data['published']) ) {
            // do our best to conver to local time
            date_default_timezone_set(LOCALTIMEZONE);
            $date = new DateTime($comment_data['published']);
            $now = new DateTime;
            $tz = $now->getTimezone();
            $date->setTimezone($tz);
            $comment_data['published'] = $date->format('Y-m-d H:i:s') . "\n";
        }


        if ( $post_id || ( isset($data['year']) && isset($data['month']) &&
            isset($data['day']) && isset($data['daycount']))
        ) {
            $this->load->model('blog/post');
            $post = null;
            if ( $post_id ) {
                $post = $this->model_blog_post->getPost($post_id);
            } else {
                $post = $this->model_blog_post->getPostByDayCount(
                    $data['year'],
                    $data['month'],
                    $data['day'],
                    $data['daycount']
                );
            }

            $interaction_type = 'mention';

            switch ($comment_data['type']) {
                case 'reply':
                    $content_text = trim($comment_data['text']);

                    if(defined('TWITTER_HANDLE')){
                        //backfed twitter reacji include the twitter handle, so lets try stripping that out
                        $content_text = str_replace(TWITTER_HANDLE, '', $content_text);
                        $content_text = trim($content_text);
                    }

                    $interaction_type = 'reply';
                    if(EmojiRecognizer::isSingleEmoji($content_text)) {
                        $interaction_type = 'reacji';
                        $comment_data['text'] = $content_text;
                    }
                break;
                case 'like':
                    $interaction_type = 'like';
                    $interaction_type = 'reacji';
                    $comment_data['text'] = html_entity_decode('&#10084;'); //a heart emoji
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
                case 'mention':
                default:
                    $interaction_type = 'mention';
                break;
            }
            $query = $this->db->query(
                "SELECT * " .
                " FROM " . DATABASE . ".webmentions " .
                " WHERE id = " . (int)$webmention_id .
                " LIMIT 1"
            );
            $mention_record = $query->row;
            $person_mention = false;
            if ( $mention_record['target_url'] == HTTP_SERVER || $mention_record['target_url'] == HTTPS_SERVER
                    || $mention_record['target_url'] == HTTP_SHORT || $mention_record['target_url'] == HTTPS_SHORT  ) {
                $person_mention = true;
            }

            //TODO: move this to some config setting
            $autoapprove = true;

            $this->load->model('blog/person');
            $person_id = $this->model_blog_person->storePerson($comment_data['author']);

            $this->db->query(
                "INSERT INTO " . DATABASE . ".interactions " .
                " SET url = '" . $comment_data['url'] . "'" .
                ", person_id ='" . $person_id . "'" .
                ((isset($comment_data['tag-of']) && !empty($comment_data['tag-of']))
                    ? ", tag_of='" . $comment_data['tag-of'] . "'"
                    : "") .
                ((isset($comment_data['text'])  && !empty($comment_data['text']))
                    ? ", content='" . $this->db->escape($comment_data['text']) . "'"
                    : "") .
                ((isset($comment_data['name'])  && !empty($comment_data['name']))
                    ? ", name='" . $this->db->escape($comment_data['name']) . "'"
                    : "") .
                ((isset($comment_data['published'])  && !empty($comment_data['published']))
                    ? ", `published`='" . $this->db->escape($comment_data['published']) . "'"
                    : ", `published`=NOW()") .
                ($person_mention ? ", `person-mention` = 1 " :  ", `person-mention` = 0 " ) .
                ", webmention_id='" . $webmention_id . "'" .
                ", type='" . $interaction_type . "'" .
                ", created_at = NOW()" .
                ($autoapprove ? ", approved=1" : '' ) .
                ""
            );


            $interaction_id = $this->db->getLastId();

            $this->db->query(
                "INSERT INTO " . DATABASE . ".interaction_post " .
                " SET interaction_id = '" . (int)$interaction_id . "'" .
                " , post_id = " . (int)$post['id'] .
                ""
            );

            $syndication_sites = $this->cache->get('syndication.sites');
            if ( !$syndication_sites ) {
                $syn_site_query = $this->db->query("SELECT * FROM " . DATABASE . ".syndication_sites");
                $syndication_sites = $syn_site_query->rows;
                $this->cache->set('syndication.sites', $syndication_sites);
            }
            if ( isset($comment_data['syndications']) ) {
                foreach ($comment_data['syndications'] as $url) {
                    // figure out what syndicaiton_site_id to use
                    foreach ($syndication_sites as $possible_site) {
                        if ( strpos($url, $possible_site['url_match']) === 0 ) {
                            $syn_site_id = $possible_site['id'];
                        }
                    }

                    $this->db->query("INSERT INTO " . DATABASE . ".interaction_syndication 
                        SET url = '" . $this->db->escape($url) . "',
                        " . (isset($syn_site_id) ? "syndication_site_id = " . (int)$syn_site_id . ", " : "" ) . "
                            interaction_id = " . (int)$interaction_id);

                    //remove any syndicated copies we have already parsed
                    $query = $this->db->query(
                        "SELECT * " .
                        " FROM " . DATABASE . ".interaction " .
                        " WHERE url='" . $this->db->escape($url) . "' " .
                        " LIMIT 1"
                    );
                    if ( !empty($query->row) ) {
                        $this->db->query(
                            "DELETE FROM " . DATABASE . ".interaction " .
                            " WHERE url='" . $this->db->escape($url) . "' " .
                            " LIMIT 1"
                        );
                    }
                }
            }

            if ( $autoapprove && $type == 'tag' ) {
                foreach ($comment_data['tags'] as $tag) {
                    if ( isset($tag['category']) ) {
                        $this->model_blog_post->addToCategory($post['id'], $tag['category']);
                    } elseif ( isset($tag['url']) ) {
                        $this->model_blog_post->addToCategory($post['id'], $tag['url']);
                    }
                }

            }

            //TODO: should remove all sub comments then reimport
            //store sub comments
            foreach ($comment_data['comments'] as $subcomment) {
                $this->addSecondLevelInteraction($interaction_id, $subcomment);
            }


            $this->db->query(
                "UPDATE " . DATABASE . ".webmentions " .
                " SET status_code = '200', " .
                " status = 'OK' " .
                " WHERE id = " . (int)$webmention_id
            );
            $this->cache->delete('interactions');

            return $interaction_id;

        } else {
            throw new Exception('Cannot look up record');
            //throwing an exception will go back to calling script and run the generic add
        }
    }

    public function editWebmention($data, $webmention_id, $comment_data, $post_id = null)
    {

        $query = $this->db->query(
            "SELECT * " .
            " FROM " . DATABASE . ".webmentions " .
            " WHERE id = " . (int)$webmention_id . " " .
            " LIMIT 1"
        );
        if ( $query->num_rows ) {
            $this->db->query(
                "UPDATE " . DATABASE . ".interactions " .
                " SET deleted_at = NOW() " .
                " WHERE webmention_id = " . (int)$webmention_id .
                " AND deleted_at IS NULL "
            );
            $new_interaction_id = $this->addWebmention($data, $webmention_id, $comment_data, $post_id);
            $this->db->query(
                "UPDATE " . DATABASE . ".webmentions " .
                " SET status='Updated' " .
                " WHERE id = " . (int)$webmention_id
            );
            return $new_interaction_id;
        }

    }



//below this has been upgraded to Interactions methods

    public function getGenericInteractions($type, $limit = 100, $skip = 0)
    {
        $data = $this->cache->get('interactions.' . $type . '.generic.' . $skip . '.' . $limit);
        if ( !$data ) {
            $query = $this->db->query(
                "SELECT * " .
                " FROM " . DATABASE . ".interactions " .
                " WHERE type='" . $type . "' " .
                " AND `person-mention` = 0 " .
                " AND deleted_at IS NULL " .
                " ORDER BY published ASC " .
                " LIMIT " . (int)$skip . ", " . (int)$limit
            );
            $data = $query->rows;
            $this->cache->set('interactions.' . $type . '.generic.' . $skip . '.' . $limit, $data);
        }
        return $data;
    }

    public function getGenericInteractionCount($type)
    {
        $data = $this->cache->get('interactions.' . $type . '.generic.count');
        if ( !$data ) {
            $query = $this->db->query(
                "SELECT COUNT(*) AS total " .
                " FROM " . DATABASE . ".interactions " .
                " WHERE type='" . $type . "' " .
                " AND `person-mention` = 0 " .
                " AND deleted_at IS NULL"
            );
            $data = $query->row['total'];
            $this->cache->set('interactions.' . $type . '.generic.count', $data);
        }
        return $data;
    }

    public function getInteractionsForPost($type, $post_id, $limit = 100, $skip = 0)
    {
        date_default_timezone_set(LOCALTIMEZONE);
        //correct my vocabulary
        if ( $type == 'comment' ) {
            $type = 'reply';
        }

        $data = $this->cache->get('interactions.' . $type . '.post.' . $post_id . '.' . $skip . '.' . $limit);
        if ( !$data ) {
            $query = $this->db->query(
                "SELECT interactions.*, " .
                " webmentions.vouch_url " .
                " FROM " . DATABASE . ".interactions " .
                " JOIN " . DATABASE . ".interaction_post " .
                " ON interactions.id = interaction_post.interaction_id " .
                " JOIN " . DATABASE . ".webmentions " .
                " ON interactions.webmention_id = webmentions.id " .
                " WHERE type='" . $type . "' " .
                " AND interaction_post.post_id = " . (int)$post_id . " " .
                " AND deleted_at IS NULL " .
                " ORDER BY published ASC " .
                " LIMIT " . (int)$skip . ", " . (int)$limit
            );
            $data = array();
            $this->load->model('blog/person');
            foreach ($query->rows as $row) {
                $person = $this->model_blog_person->getPerson($row['person_id']);
                $row['author'] = $person;

                $row['published'] = date("c", strtotime($row['published']));

                $second_level_query = $this->db->query(
                    "SELECT i.*, " .
                    " FROM " . DATABASE . ".interactions i " .
                    " JOIN " . DATABASE . ".interaction_interaction ii ON ii.child_id = i.id " .
                    " WHERE ii.parent_id='" . $row['id'] . "' " .
                    " ORDER BY published ASC "
                );

                $row['comments'] = $second_level_query->rows;
                
                foreach($row['comments'] as &$secondlev){
                    $row['author'] = $this->model_blog_person->getPerson($row['person_id']);
                    $secondlev['published'] = date("c", strtotime($secondlev['published']));
                }

                $data[] = $row;
            }

            $this->cache->set('interactions.' . $type . '.post.' . $post_id . '.' . $skip . '.' . $limit, $data);
        }
        return $data;
    }

    public function getInteractionCountForPost($type, $post_id)
    {
        $data = $this->cache->get('interactions.' . $type . '.post.count.' . $post_id);
        if ( !$data ) {
            $query = $this->db->query(
                "SELECT COUNT(*) AS total " .
                " FROM " . DATABASE . ".interactions " .
                " JOIN " . DATABASE . ".interaction_post " .
                " ON interactions.id = interaction_post.interaction_id " .
                " WHERE type='" . $type . "' " .
                " AND interaction_post.post_id = " . (int)$post_id . " " .
                " AND deleted_at IS NULL"
            );
            $data = $query->row['total'];
            $this->cache->set('interactions.' . $type . '.post.count.' . $post_id, $data);
        }
        return $data;
    }

    public function getSyndications($interaction_id)
    {
        $data = $this->cache->get('syndications.interaction.' . $interaction_id);
        if ( !$data ) {
            $query = $this->db->query(
                "SELECT * " .
                " FROM " . DATABASE . ".interaction_syndication " .
                " JOIN " . DATABASE . ".syndication_sites " .
                " ON interaction_syndication.syndication_site_id = syndication_site.id " .
                " WHERE interaction_id = " . (int)$interaction_id
            );

            $data = $query->rows;
            $this->cache->set('syndications.interaction.' . $interaction_id, $data);
        }
        return $data;
    }

    public function getRecentInteractions($limit = 20, $skip = 0)
    {
        $data = $this->cache->get('interactions.recent.post.' . $skip . '.' . $limit);
        if ( !$data ) {
            $this->load->model('blog/post');

            $query = $this->db->query(
                "SELECT interactions.*, " .
                " webmentions.vouch_url, " .
                " webmentions.target_url " .
                " FROM " . DATABASE . ".interactions " .
                " JOIN " . DATABASE . ".webmentions " .
                " ON interactions.webmention_id = webmentions.id " .
                " WHERE deleted_at IS NULL " .
                " ORDER BY published DESC " .
                " LIMIT " . (int)$skip . ", " . (int)$limit
            );
            $data = array();
        $this->load->model('blog/person');
            foreach ($query->rows as $row) {

                $person = $this->model_blog_person->getPerson($row['person_id']);

                $data[] = array_merge($row, array(
                    'post' => $this->model_blog_post->getPost($row['post_id']),
                    'author' => $person

                ));
            }
            $this->cache->set('interactions.recent.' . $skip . '.' . $limit, $data);
        }
        return $data;
    }

    public function addSecondLevelInteraction($interaction_id, $data)
    {
        $this->load->model('blog/person');
        $person_id = $this->model_blog_person->storePerson($data['author']);

        if ( isset($data['published']) && !empty($data['published']) ) {
            // do our best to conver to local time
            date_default_timezone_set(LOCALTIMEZONE);
            $date = new DateTime($data['published']);
            $now = new DateTime;
            $tz = $now->getTimezone();
            $date->setTimezone($tz);
            $data['published'] = $date->format('Y-m-d H:i:s') . "\n";
        }

        $this->db->query(
            "INSERT INTO " . DATABASE . ".interactions " .
            " SET url = '" . $this->db->escape($data['url']) . "', " .
            " type='reply'," .
            " person_id=" . (int)$person_id . ", " .
            ((isset($data['text']) && !empty($data['text']))
                ? " content='" . $this->db->escape($data['text']) . "', "
                : "") .
            ((isset($data['name'])  && !empty($data['name']))
                ? " name='" . $this->db->escape($data['name']) . "', "
                : "") .
            ((isset($data['published'])  && !empty($data['published']))
                ? " `published`='" . $this->db->escape($data['published']) . "',"
                : " `published`=NOW(),") .
            " created_at = NOW()"
        );


        $second_level_interaction_id = $this->db->getLastId();

        $this->db->query(
            "INSERT INTO " . DATABASE . ".interaction_interaction " .
            " SET child_id=" . (int)$second_level_interaction_id . ", " .
            " parent_id=" . (int)$interaction_id 
        );


    }

}
