<?php   
class ControllerCommonDashboard extends Controller {   
	public function index() {



		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => '',
			'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], 'SSL')
		);

		$data['breadcrumbs'][] = array(
			'text' => '',
			'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], 'SSL')
		);

		$data['token'] = $this->session->data['token'];


        $data['new_post'] = $this->url->link('blog/post/insert');
        $data['new_note'] = $this->url->link('blog/note/insert');

        $this->load->model('blog/post');
        $this->load->model('blog/comment');
        $this->load->model('blog/like');
        $this->load->model('blog/mention');

        $data['posts'] = array();

		foreach ($this->model_blog_post->getRecentPosts(5) as $result) {
                $comment_count = $this->model_blog_comment->getCommentCountForPost($result['post_id']);
                $like_count = $this->model_blog_like->getLikeCountForPost($result['post_id']);
                $mention_count = $this->model_blog_mention->getMentionCountForPost($result['post_id']);

                $data['posts'][] = array_merge($result, array(
                    'mention_count' => $mention_count,
                    'comment_count' => $comment_count,
                    'like_count' => $like_count
                    ));
    	}

		$data['header'] = $this->load->controller('common/header');
		$data['menu'] = $this->load->controller('common/menu');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('common/dashboard.tpl', $data));
	}

}
