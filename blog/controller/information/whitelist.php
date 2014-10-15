<?php  
class ControllerInformationWhitelist extends Controller {
    public function index(){
		$this->document->setTitle('Accepting Webmentions and Comments From...');
		$data['title'] = 'Acceptable Webmention Sources';

		$data['header'] = $this->load->controller('common/header');
		$data['footer'] = $this->load->controller('common/footer');

        // pass if we are the owner or not down to the view
        if($this->session->data['is_owner']){
            $data['is_owner'] = true;
        }

		$this->document->setDescription($this->config->get('config_meta_description'));

        //get the white list
        $this->load->model('webmention/vouch');

        // if we are the site owner we would like to get the list of ALL whitelisted users, including those we do not pulish
        $whitelist = $this->model_webmention_vouch->getWhitelist($this->session->data['is_owner']);
        $data['whitelist']  = array();

        foreach($whitelist as $entry){
            $data['whitelist'][]  = array(
                'domain' => $whitelist['domain'],
                'public' => true,
                'delete' => $this->url->link('information/whitelist/delete'),
                'make_public' => $this->url->link('information/whitelist/public'),
                'make_private' => $this->url->link('information/whitelist/private')
            );
        }

		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/information/whitelist.tpl')) {
			$this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/information/whitelist.tpl', $data));
		} else {
			$this->response->setOutput($this->load->view('default/template/information/whitelist.tpl', $data));
		}
    }
}
?>