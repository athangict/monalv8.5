<?php
namespace ApplicationTest\View\Helper;

use Acl\Model\NotifyTable;
use Application\View\Helper\Notificationhelper;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\TemplateMapResolver;
use PHPUnit\Framework\TestCase;

final class NotificationHelperTest extends TestCase
{
	public function testDropdownHeadingCountsOnlyUnreadNotifications(): void
	{
		$source = file_get_contents(dirname(__DIR__, 3) . '\view\partial\navbar-top.phtml');
		$start = strpos($source, '<li class="dropdown-header">');
		$end = strpos($source, '</li>', $start) + strlen('</li>');
		$file = tempnam(sys_get_temp_dir(), 'notification-heading-');
		try {
			file_put_contents($file, substr($source, $start, $end - $start));
			$renderer = new PhpRenderer();
			$renderer->setResolver(new TemplateMapResolver(['notification-heading' => $file]));
			foreach ([0, 1, 3] as $count) {
				$output = $renderer->render('notification-heading', ['unreadNotificationCount' => $count]);
				$text = trim(preg_replace('/\s+/', ' ', str_replace('&nbsp;', ' ', strip_tags($output))));
				self::assertSame($count === 0 ? 'Notifications' : $count . ($count === 1 ? ' Notification' : ' Notifications'), $text);
			}
		} finally {
			unlink($file);
		}
	}

	public function testNotificationBadgeIsShownOnlyForPositiveUnreadCounts(): void
	{
		$source = file_get_contents(dirname(__DIR__, 3) . '\view\partial\navbar-top.phtml');
		$start = strpos($source, '<?php if ($unreadNotificationCount > 0): ?>');
		self::assertNotFalse($start);
		$end = strpos($source, '<?php endif; ?>', $start) + strlen('<?php endif; ?>');
		$file = tempnam(sys_get_temp_dir(), 'notification-badge-');
		try {
			file_put_contents($file, substr($source, $start, $end - $start));
			$renderer = new PhpRenderer();
			$renderer->setResolver(new TemplateMapResolver(['notification-badge' => $file]));
			self::assertSame('', trim($renderer->render('notification-badge', ['unreadNotificationCount' => 0])));
			$output = $renderer->render('notification-badge', ['unreadNotificationCount' => 3]);
			self::assertStringContainsString('<span class="badge badge-important">3</span>', $output);
		} finally {
			unlink($file);
		}
	}

	public function testViewedNotificationsRemainListedAndOnlyUnreadItemsAreCounted(): void
	{
		$table = $this->createMock(NotifyTable::class);
		$table->expects(self::exactly(2))->method('getLimit')->with(['n.user' => 6])->willReturn([
			['id' => 10, 'user' => 6, 'flag' => '0', 'description' => 'New requisition',
				'route' => 'transaction', 'action' => 'viewcredit', 'created' => '', 'key' => 44, 'priority' => 1],
			['id' => 11, 'user' => 6, 'flag' => '1', 'description' => 'Viewed requisition',
				'route' => 'transaction', 'action' => 'viewcredit', 'created' => '', 'key' => 45, 'priority' => 0],
		]);
		$table->expects(self::exactly(2))->method('getCount')->with(['user' => 6, 'flag' => '0'])->willReturn(1);
		$helper = new Notificationhelper($table);
		for ($i = 0; $i < 2; $i++) {
			$notifications = $helper(6);
			self::assertCount(2, $notifications);
			self::assertSame(['0', '1'], array_column($notifications, 'flag'));
			self::assertSame([1, 1], array_column($notifications, 'count'));
		}
	}

	public function testNotificationDropdownRendersRedUnreadAndGreenViewedDots(): void
	{
		$source = file_get_contents(dirname(__DIR__, 3) . '\view\partial\navbar-top.phtml');
		$start = strpos($source, '<?php foreach($notificationResult as $notify): ?>');
		$end = strpos($source, '<?php endforeach; ?>', $start) + strlen('<?php endforeach; ?>');
		$file = tempnam(sys_get_temp_dir(), 'notification-view-');
		try {
			file_put_contents($file, substr($source, $start, $end - $start));
			$renderer = new PhpRenderer();
			$renderer->setResolver(new TemplateMapResolver(['notification-items' => $file]));
			$renderer->getHelperPluginManager()->setService('url', new class extends AbstractHelper {
				public function __invoke($route, $params): string
				{
					return '/' . $route . '/' . $params['action'] . '/' . $params['id'];
				}
			});
			$output = $renderer->render('notification-items', ['notificationResult' => [
				['id' => 10, 'flag' => 0, 'route' => 'transaction', 'action' => 'viewcredit', 'key' => 44, 'desc' => 'New'],
				['id' => 11, 'flag' => 1, 'route' => 'transaction', 'action' => 'viewcredit', 'key' => 45, 'desc' => 'Viewed'],
			]]);
			self::assertStringContainsString('fa-circle red', $output);
			self::assertStringContainsString('fa-circle green', $output);
			self::assertSame(1, substr_count($output, 'notification-unread-dot'));
			self::assertStringContainsString('red notification-unread-dot', $output);
			self::assertStringContainsString('Unread', $output);
			self::assertStringContainsString('Viewed', $output);
			self::assertStringContainsString('/transaction/viewcredit/44-1-10', $output);
			self::assertStringContainsString('/transaction/viewcredit/45-1-11', $output);
		} finally {
			unlink($file);
		}
	}
}
