<?php
namespace FormTheory\Tests;

use Brain\Monkey\Functions;

class NotifyTest extends TestCase {

	private $mails = array();

	protected function setUp(): void {
		parent::setUp();
		$mails = &$this->mails;
		Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body, $headers = array(), $att = array() ) use ( &$mails ) {
			$mails[] = compact( 'to', 'subject', 'body', 'headers', 'att' );
			return true;
		} );
		Functions\when( 'admin_url' )->alias( function ( $p = '' ) {
			return 'https://x.test/wp-admin/' . $p;
		} );
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '/up' ) );
		Functions\when( 'trailingslashit' )->alias( function ( $s ) {
			return rtrim( $s, '/' ) . '/';
		} );
	}

	private function form( array $notifications = null ) {
		$cfg = array(
			'fields'         => array(
				array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'cond' => array( 'enabled' => false, 'rules' => array() ) ),
				array( 'id' => 'topic', 'type' => 'select', 'label' => 'Topic', 'cond' => array( 'enabled' => false, 'rules' => array() ) ),
				array( 'id' => 'doc', 'type' => 'file', 'label' => 'Doc', 'cond' => array( 'enabled' => false, 'rules' => array() ) ),
			),
			'notify_to'      => 'a@x.test',
			'notify_subject' => 'Legacy subject',
		);
		if ( null !== $notifications ) {
			$cfg['notifications'] = $notifications;
		}
		return array( 'id' => 1, 'title' => 'Contact', 'config' => \FormTheory_Fields::migrate_config( $cfg ) );
	}

	private function n( array $over ) {
		return array_merge( array(
			'id'           => 'n1',
			'to'           => 'a@x.test',
			'subject'      => 'S',
			'body'         => '{all_fields}',
			'reply_to'     => '',
			'attach_files' => false,
			'cond'         => array( 'enabled' => false, 'action' => 'show', 'logic' => 'all', 'rules' => array() ),
		), $over );
	}

	public function test_migrate_legacy_config() {
		$form = $this->form();
		$this->assertCount( 1, $form['config']['notifications'] );
		$n = $form['config']['notifications'][0];
		$this->assertSame( 'a@x.test', $n['to'] );
		$this->assertSame( 'Legacy subject', $n['subject'] );
		$this->assertSame( '{field:email}', $n['reply_to'] );
	}

	public function test_legacy_sends_one_mail() {
		\FormTheory_Notify::send( $this->form(), array( 'email' => 'u@x.test' ), 7 );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( 'a@x.test' ), $this->mails[0]['to'] );
		$this->assertContains( 'Reply-To: u@x.test', $this->mails[0]['headers'] );
		$this->assertStringContainsString( 'Email: u@x.test', $this->mails[0]['body'] );
	}

	public function test_conditional_notification_skipped() {
		$cond = array( 'enabled' => true, 'action' => 'show', 'logic' => 'all', 'rules' => array( array( 'field' => 'topic', 'op' => 'is', 'value' => 'Sales' ) ) );
		$form = $this->form( array( $this->n( array( 'cond' => $cond ) ) ) );
		\FormTheory_Notify::send( $form, array( 'topic' => 'Support' ), 7 );
		$this->assertCount( 0, $this->mails );
		\FormTheory_Notify::send( $form, array( 'topic' => 'Sales' ), 8 );
		$this->assertCount( 1, $this->mails );
	}

	public function test_to_field_tag_resolves_and_validates() {
		$form = $this->form( array( $this->n( array( 'to' => '{field:email}, ops@x.test' ) ) ) );
		\FormTheory_Notify::send( $form, array( 'email' => 'bad' ), 7 );
		$this->assertSame( array( 'ops@x.test' ), $this->mails[0]['to'] );
		\FormTheory_Notify::send( $form, array( 'email' => 'u@x.test' ), 8 );
		$this->assertSame( array( 'u@x.test', 'ops@x.test' ), $this->mails[1]['to'] );
	}

	public function test_no_valid_recipient_sends_nothing() {
		$form = $this->form( array( $this->n( array( 'to' => '{field:email}' ) ) ) );
		\FormTheory_Notify::send( $form, array( 'email' => 'bad' ), 7 );
		$this->assertCount( 0, $this->mails );
	}

	public function test_attach_files_paths() {
		$form = $this->form( array( $this->n( array( 'attach_files' => true ) ) ) );
		\FormTheory_Notify::send( $form, array( 'doc' => array( 'file' => '../abc.pdf', 'name' => 'cv.pdf', 'size' => 1 ) ), 7 );
		$this->assertSame( array( '/up/formtheory-private/abc.pdf' ), $this->mails[0]['att'] );
	}

	public function test_subject_header_safe() {
		$form = $this->form( array( $this->n( array( 'subject' => 'New {field:topic}' ) ) ) );
		\FormTheory_Notify::send( $form, array( 'topic' => "x\r\nBcc: e@x.test" ), 7 );
		$this->assertSame( 'New x Bcc: e@x.test', $this->mails[0]['subject'] );
	}

	public function test_sanitize_notifications() {
		$cfg = \FormTheory_Fields::sanitize_config( array(
			'fields'        => array( array( 'id' => 'email', 'type' => 'email' ) ),
			'notifications' => array(
				array( 'to' => "a@x.test, {field:email}, {admin_email}, <b>bad", 'subject' => "S\n2", 'body' => '<script>x</script>B', 'attach_files' => '1' ),
			),
		) );
		$n = $cfg['notifications'][0];
		$this->assertSame( 'a@x.test,{field:email},{admin_email}', $n['to'] );
		$this->assertSame( 'S 2', $n['subject'] );
		$this->assertSame( 'xB', $n['body'] );
		$this->assertTrue( $n['attach_files'] );
		$this->assertSame( 'n1', $n['id'] );
	}
}
