<?php
class ModelWebmentionVouch extends Model {
    public function recordReferer($referer)
    {

        $query = $this->db->query(
            "SELECT * " .
            " FROM  " . DATABASE . ".referer_receive_queue " .
            " WHERE url='" . $this->db->escape($referer) . "' " .
            " LIMIT 1"
        );
        if (!$query->num_rows == 0) {
            //start out with some basic tests to make sure we have something useful
            if (!isset($referer)) {
                return;
            }
            $referer = trim($referer);
            if (empty($referer) || $referer == '-') {
                return;
            }
            // make sure this isn't an internal link
            $short_self  = trim(str_replace(array('http://', 'https://'), array('',''), HTTP_SERVER), '/');
            $trimmed_ref  = trim(str_replace(array('http://', 'https://'), array('',''), $referer), '/');
            if (strpos($trimmed_ref, $short_self) === 0) {
                return;
            }

            //make sure the link isn't just the main page as this is usually going to change quickly
            $ref_domain = parse_url('http://' . $trimmed_ref, PHP_URL_HOST);
            if ($trimmed_ref == $ref_domain) {
                return;
            }

            //make sure we don't already have this vouch
            $query = $this->db->query(
                "SELECT * " .
                " FROM " . DATABASE . ".vouches " .
                " WHERE vouch_url = '" . $this->db->escape($referer) . "' " .
                " OR vouch_url_alt = '" . $this->db->escape($referer) . "'"
            );
            if (!empty($query->rows)) {
                return;
            }

            //now we want to loop through all parts of our domain and make sure it isn't blacklisted.
            // so if we have abc.def.ghi.com well check for
            //      abc.def.ghi.com
            // then def.ghi.com
            // then ghi.com
            //  if any of these are found, the referer is ignored

            $domain_parts = explode('.', $ref_domain);

            for ($i = count($domain_parts); $i >= 2; $i--) {
                    $search_val = implode('.', array_slice($domain_parts, -$i));
                    $query = $this->db->query("SELECT * " .
                        " FROM " . DATABASE . ".untrusted_vouchers " .
                        " WHERE domain = '" . $this->db->escape($search_val) . "'");
                if (!empty($query->rows)) {
                    return;
                }
            }

            // referer makes it in to the aysnc queue
            $this->db->query("INSERT INTO " . DATABASE . ".referer_receive_queue SET url='" . $this->db->escape($referer) . "'");
        }
    }

    //async processor
    // this will loop through all entries in the queue and validate that they have valid links back to my site
    public function processReferers()
    {
        $query = $this->db->query("SELECT id " .
            " FROM " . DATABASE . ".referer_receive_queue rrq JOIN " . DATABASE . ".referrer_ignore ri on rrq.url LIKE ri.url_match ;");

        foreach ($query->rows as $queue_entry) {
            $this->db->query("DELETE FROM " . DATABASE . ".referer_receive_queue where id = " . (int)$queue_entry['id']);
        }

        $query = $this->db->query("SELECT * " .
            " FROM " . DATABASE . ".referer_receive_queue " .
            " LIMIT 1;");

        $entry = $query->row;

        while ($entry && !empty($entry)) {
            $referer = $entry['url'];
            //curl site, find url, check if set no-follow
            $c = curl_init();
            curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($c, CURLOPT_URL, $referer);
            curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($c, CURLOPT_MAXREDIRS, 20);
            $referer = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
            $page_content = curl_exec($c);

            $reg_ex_match = '/(href=[\'"](?<href>[^\'"]+)[\'"][^>]*(rel=[\'"](?<rel>[^\'"]+)[\'"])?)/';
            $matches = array();
            preg_match_all($reg_ex_match, $page_content, $matches);

            $short_self  = trim(str_replace(array('http://', 'https://'), array('',''), HTTP_SERVER), '/');

            $valid_link_found = false;
            for ($i = 0; $i < count($matches['href']); $i++) {
                $href = strtolower($matches['href'][$i]);
                $rel = strtolower($matches['rel'][$i]);

                if (strpos($rel, "nofollow") === false) {
                    if (strpos($href, $short_self) !== false) {
                        $valid_link_found = true;
                    }
                }
            }
            if (!$valid_link_found) {
                //repeat all that for rel before href (because preg_match_all doesn't like reused names)
                $reg_ex_match = '/(rel=[\'"](?<rel>[^\'"]+)[\'"][^>]*href=[\'"](?<href>[^\'"]+)[\'"])/';
                $matches = array();
                preg_match_all($reg_ex_match, $page_content, $matches);

                for ($i = 0; $i < count($matches['href']); $i++) {
                    $href = strtolower($matches['href'][$i]);
                    $rel = strtolower($matches['rel'][$i]);

                    if (strpos($rel, "nofollow") === false) {
                        if (strpos($href, $short_self) !== false) {
                            $valid_link_found = true;
                        }
                    }
                }

            }

            if ($valid_link_found) {
                //parse out the domain to store this under

                // parse url requires http at the beginning
                if (strpos($referer, 'http://') === 0  || strpos($referer, 'https://') === 0 ) {
                    $domain = parse_url($referer, PHP_URL_HOST);
                } else {
                    $domain = parse_url('http://' . $referer, PHP_URL_HOST);
                }

                //look for existing record in DB for this domain
                $query = $this->db->query("SELECT * FROM " . DATABASE . ".vouches WHERE domain = '" . $this->db->escape($domain) . "'");
                $existing_vouch_entry = $query->row;

                $fill_alt = false;
                $already_filled = false;

                $already_existing = false;

                //do i have 2 possible vouches
                if (isset($existing_vouch_entry) && !empty($existing_vouch_entry)) {
                    $already_existing = true;

                    if (isset($existing_vouch_entry['vouch_url_alt']) &&  !empty($existing_vouch_entry['vouch_url_alt'])) {
                        $alread_filled = true;
                    } elseif (isset($existing_vouch_entry['vouch_url']) &&  !empty($existing_vouch_entry['vouch_url'])) {
                        $fill_alt = true;
                        if ($existing_vouch_entry['vouch_url'] == $referer) { // we don't want vouch_url and vouch_url_alt to just be the same
                            $already_filled = true;
                        }
                    }
                }

                if (!$already_filled) {
                    $this->db->query(
                        ($already_existing ? "UPDATE " : "INSERT INTO ") . DATABASE . ".vouches " .
                        " SET  vouch_url" . ($fill_alt ? "_alt" : "") . " = '" . $this->db->escape($referer) . "' " .
                        (!$already_existing ? ", " : " WHERE ") . " domain='" . $this->db->escape($domain) . "'"
                    );
                }

            }
            //remove old entry from queue
            $this->db->query("DELETE FROM " . DATABASE . ".referer_receive_queue where id = " . (int)$entry['id']);

            //get the next queued item
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".referer_receive_queue " .
                " LIMIT 1");
            $entry = $query->row;
        }
    }

    // this function does a best-effort attempt to find a site that might provide a valid vouch for this site to the webmention_target_url
    public function getPossibleVouchFor($webmention_target_url, $recurse = true)
    {
        //$this->log->write('call getPossibleVouchFor with '.$webmention_target_url);

        //first we download the URL os we can parse that page for clues as well as learn the real url if there are any redirects
        $c = curl_init();
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_URL, $webmention_target_url);
        curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($c, CURLOPT_MAXREDIRS, 20);
        $real_url = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
        $page_content = curl_exec($c);


        //we strip down the true url, to get the domain

        // parse url requires http at the beginning
        $domain = parse_url($real_url, PHP_URL_HOST);
        if (!$domain) {
            $domain = parse_url('http://' . $real_url, PHP_URL_HOST);
        }

        //we will start with this page and see if we find some valid vouch value there.

        // rel is option on this section
        $reg_ex_match = '/(href=[\'"](?<href>[^\'"]+)[\'"][^>]*(rel=[\'"](?<rel>[^\'"]+)[\'"])?)/';
        $matches = array();
        preg_match_all($reg_ex_match, $page_content, $matches);


        $valid_link_found = false;
        for ($i = 0; $i < count($matches['href']); $i++) {
            $href = strtolower($matches['href'][$i]);
            $rel = strtolower($matches['rel'][$i]);

            if (strpos($rel, "nofollow") === false) { // this will work if rel is blank too!
                $vouch = $this->vouchSearch($href);
                if ($vouch) {
                    //$this->log->write('found '.$vouch);
                    return $vouch;
                }
            }

            //if we are supposed to recurse on this page and it has a rel="me" then we will
            //  check to see if the page is in this domain,  if so we check that page as well.
            //  this is in hopes of finding a whitelist or contacts page of some sort
            if ($recurse && strpos($rel, "me") !== false) {
                if (strpos($href, "//") === 0 && strpos($href, $domain) == 2) {
                    $result = $this->getPossibleVouchFor('http:' . $href, false);
                } elseif (strpos($href, "/") === 0) {
                    $result = $this->getPossibleVouchFor('http://' . $domain . $href, false);
                } elseif (strpos($href, $domain) !== false) {
                    $href_domain = parse_url($href, PHP_URL_HOST);
                    if (!$href_domain) {
                        $href_domain = parse_url('http://' . $href, PHP_URL_HOST);
                    }
                    if ($domain == $href_domain) {
                        $result = $this->getPossibleVouchFor($href, false);
                    }
                }
                //if we found a result, we can stop there
                if ($result) {
                    return $result;
                }
            }
        }
        if (!$valid_link_found) {
            //repeat all that for rel before href (because preg_match_all doesn't like reused names)
            $reg_ex_match = '/(rel=[\'"](?<rel>[^\'"]+)[\'"][^>]*href=[\'"](?<href>[^\'"]+)[\'"])/';
            $matches = array();
            preg_match_all($reg_ex_match, $page_content, $matches);

            for ($i = 0; $i < count($matches['href']); $i++) {
                $href = strtolower($matches['href'][$i]);
                $rel = strtolower($matches['rel'][$i]);

                if (strpos($rel, "nofollow") === false) {
                    $vouch = $this->vouchSearch($href);
                    if ($vouch) {
                        //$this->log->write('found '.$url);
                        return $vouch;
                    }
                }

                //if we are supposed to recurse on this page and it has a rel="me" then we will
                //  check to see if the page is in this domain,  if so we check that page as well.
                //  this is in hopes of finding a whitelist or contacts page of some sort
                if ($recurse && strpos($rel, "me") !== false) {
                    if (strpos($href, "//") === 0 && strpos($href, $domain) == 2) {
                        $result = $this->getPossibleVouchFor('http:' . $href, false);
                    } elseif (strpos($href, "/") === 0) {
                        $result = $this->getPossibleVouchFor('http://' . $domain . $href, false);
                    } elseif (strpos($href, $domain) !== false) {
                        $href_domain = parse_url($href, PHP_URL_HOST);
                        if (!$href_domain) {
                            $href_domain = parse_url('http://' . $href, PHP_URL_HOST);
                        }
                        if ($domain == $href_domain) {
                            $result = $this->getPossibleVouchFor($href, false);
                        }
                    }
                    //if we found a result, we can stop there
                    if ($result) {
                        return $result;
                    }
                }

            }
        }


        if ($recurse) {
            //try the homepage
            if ($real_homepage_url == $webmention_target_url) {
                $result = $this->getPossibleVouchFor($real_homepage_url, false);
            }
            if (!$result) {
            }
            return $result;
        }
        return false;
    }


    //check the database if we have a link back to this site for this URL
    //return the URL we can use as a vouch if found
    //return false if not found
    public function vouchSearch($url)
    {
        //$this->log->write('call vouchSearch with '.$url);

        // parse url requires http at the beginning
        if (strpos($url, 'http://') === 0  || strpos($url, 'https://') === 0 ) {
            $url_domain = parse_url($url, PHP_URL_HOST);
        } else {
            $url_domain = parse_url('http://' . $url, PHP_URL_HOST);
        }

        $query = $this->db->query("SELECT * FROM " . DATABASE . ".vouches WHERE domain = '" . $this->db->escape($url_domain) . "'");
        $entry = $query->row;

        if ($entry && !empty($entry)) {
            return $entry['vouch_url'];
        } else {
            return false;
        }

    }

    public function isWhiteListed($url)
    {
        if (strpos($url, 'http://') === 0  || strpos($url, 'https://') === 0 ) {
            $url_domain = parse_url($url, PHP_URL_HOST);
        } else {
            $url_domain = parse_url('http://' . $url, PHP_URL_HOST);
        }
        $query = $this->db->query("SELECT * FROM " . DATABASE . ".whitelist WHERE domain = '" . $this->db->escape($url_domain) . "'");
        return (!empty($query->row));
    }

    public function getWhitelist($getAll = false)
    {
        $data = $this->cache->get('whitelist.' . ($getAll ? '1' : '0'));
        if (!$data) {
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".whitelist " . ($getAll ? "" : "WHERE public=1"));
            $data = $query->rows;
            $this->cache->set('whitelist.' . ($getAll ? '1' : '0'), $data);
        }
        return $data;
    }

    public function addWhitelistEntry($url)
    {
        $domain = parse_url($url, PHP_URL_HOST);
        $short_self = trim(str_replace(array('http://', 'https://'), array('',''), HTTP_SERVER), '/');
        $mini_short_self = trim(str_replace(array('http://', 'https://'), array('',''), HTTP_SHORT), '/');

        // so that we don't add our own site to the list, though technically it doesn't matter, its still confusing
        if ($domain == $short_self || $domain == $mini_short_self) {
            return;
        }

        if (!$domain || empty($domain)) {
            $domain = parse_url('http://' . $url, PHP_URL_HOST);
        }
        if ($domain && !empty($domain)) {
            $query = $this->db->query("SELECT * FROM " . DATABASE . ".whitelist WHERE domain='" . $this->db->escape($domain) . "'");
            $found = $query->row;
            if (!$found || empty($found)) {
                $this->db->query("INSERT INTO " . DATABASE . ".whitelist(domain, public) VALUES ('" . $this->db->escape($domain) . "', 0)");
            }
        }
    }

    public function setWhitelistEntryPublic($id)
    {
        $this->db->query("UPDATE " . DATABASE . ".whitelist SET public = 1 WHERE id = " . (int)$id . " LIMIT 1");
        $this->cache->delete('whitelist');
    }
    public function setWhitelistEntryPrivate($id)
    {
        $this->db->query("UPDATE " . DATABASE . ".whitelist SET public = 0 WHERE id = " . (int)$id . " LIMIT 1");
        $this->cache->delete('whitelist');
    }
    public function removeWhitelistEntry($id)
    {
        $this->db->query("DELETE FROM " . DATABASE . ".whitelist WHERE id = " . (int)$id . " LIMIT 1");
        $this->cache->delete('whitelist');
    }


}
