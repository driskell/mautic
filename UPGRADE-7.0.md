# Backwards compatibility breaking changes

## Platform requirements
- The minimum required PHP version has been increased from **8.1** to **8.2**.
- The minimum required MySQL version has been increased from **5.7.14** to **8.4.0**.
- The minimum required MariaDB version has been increased from **10.2.7** to **10.11.0**.

## Removed features
- The ability to update Mautic in the browser (via user interface) has been removed. To update Mautic, use the **command line** instead.
- The API Limiter was removed temporarily. Shoud be added back before the M7-stable is released. https://github.com/mautic/mautic/pull/14876
- The `track_by_tracking_url` configuration setting has been removed. Identification of contacts via the `ct` parameter in tracking URLs was already the default behavior.

## Removed code

- Deprecated class `Mautic\MessengerBundle\MessageHandler\TestHandler` removed with no replacement.
- Deprecated class `Mautic\CoreBundle\Factory\MauticFactory` removed. Use dependency injection instead.
- Deprecated class `Mautic\CampaignBundle\Service\Campaign` removed with no replacement.
- Deprecated class `Mautic\CampaignBundle\Form\Type\CampaignEventLeadChangeType` removed with no replacement.
- Deprecated class `Mautic\CoreBundle\Event\CustomFormEvent` removed with no replacement.
- Deprecated class `Mautic\CoreBundle\Helper\UTF8Helper` removed with no replacement.
- Deprecated class `Mautic\CoreBundle\Test\MauticSqliteTestCase` removed with no replacement.
- Deprecated class `Mautic\CoreBundle\Twig\Extension\EmojiExtension` removed with no replacement.
- Deprecated class `Mautic\EmailBundle\Form\Type\AbTestPropertiesType` removed with no replacement.
- Deprecated class `Mautic\EmailBundle\Helper\RequestStorageHelper` removed with no replacement.
- Deprecated class `Mautic\FormBundle\Tests\FormTestAbstract` removed with no replacement.
- Deprecated class `Mautic\LeadBundle\Helper\PointEventHelper` removed with no replacement.
- Deprecated class `Mautic\MarketplaceBundle\Exception\InstallException` removed with no replacement.
- Deprecated class `Mautic\NotificationBundle\Event\NotificationClickEvent` removed with no replacement.
- Deprecated class `Mautic\NotificationBundle\Event\SendingNotificationEvent` removed with no replacement.
- Deprecated class `Mautic\NotificationBundle\Form\Type\ConfigType` removed with no replacement.
- Deprecated class `Mautic\SmsBundle\Api\AbstractSmsApi` removed with no replacement.
- Deprecated class `Mautic\SmsBundle\Api\TwilioApi` removed with no replacement.
- Deprecated interface `Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\AuthorizatorInterface` removed. Use `Mautic\IntegrationsBundle\Integration\Interfaces\AuthenticationInterface` instead.
- Deprecated interface `Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\CredentialsInterface` removed. Use `Mautic\IntegrationsBundle\Auth\Provider\AuthCredentialsInterface` instead.
- Deprecated class `Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\AbstractClientFactory` removed with no replacement.
- Deprecated trait `Mautic\IntegrationsBundle\Integration\BC\BcIntegrationSettingsTrait` removed with no replacement.
- Deprecated exception classes `Mautic\CampaignBundle\Executioner\Scheduler\Exception\ExecutionProhibitedException` and `NotTimeYetException` removed with no replacement.
- Deprecated form data transformers removed:
  - `Mautic\CoreBundle\Form\DataTransformer\DatetimeToStringTransformer`
  - `Mautic\CoreBundle\Form\DataTransformer\EmojiToHtmlTransformer`  
  - `Mautic\CoreBundle\Form\DataTransformer\NullToEmptyTransformer`
- Deprecated plugin classes removed:
  - `MauticFullContactBundle\Services\FullContact_API`
  - `MauticSocialBundle\Form\Type\TwitterCustomType`
- File `app/AppCache.php` removed as it was no longer used.

## Removed Composer dependencies
- `symfony/yaml` see https://github.com/mautic/mautic/pull/14850
- `symfony/security-acl` see https://github.com/mautic/mautic/pull/14850
- `symfony/css-selector` see https://github.com/mautic/mautic/pull/14850
- `symfony/templating` see https://github.com/mautic/mautic/pull/14850
- `noxlogic/ratelimit-bundle` see https://github.com/mautic/mautic/pull/14876
- `symfony/amqp-messenger` see https://github.com/mautic/mautic/pull/14883
- `php-amqplib/rabbitmq-bundle` see https://github.com/mautic/mautic/pull/14883
- `bandwidth-throttle/token-bucket` see https://github.com/mautic/mautic/pull/15271

## BC breaks in the code

### PHP
- Removed `Mautic\DashboardBundle\Dashboard\Widget::FORMAT_MYSQL` constant. Use `DateTimeHelper::FORMAT_DB_DATE_ONLY` instead.
- Removed `Mautic\ApiBundle\Security\OAuth2\Firewall::OAuthListener` class as it was empty. Use `FOS\OAuthServerBundle\Security\Firewall\OAuthListener` instead.
- Removed `Mautic\LeadBundle\Segment\Query\Filter\SegmentReferenceFilterQueryBuilder` as unused.
- `Mautic\EmailBundle\Model\SendEmailToContact::send()` now returns `bool`, which is `false` when another sender already claimed the contact for the segment email. Its protected `queueTokenizedEmail()` and `sendStandardEmail()` methods were removed, and `createContactStatEntry()` now returns `bool`.

### Javascript
- Removed `Mautic.insertTextInEditor` function. Use `Mautic.insertHtmlInEditor` instead.

# Notes

- Migration file `app/migrations/Version20230522141144.php` has been removed. If you do not use the Mautic Citrix plugin or a fork of it, you can manually drop the `plugin_citrix_events` table from the database, as it is no longer used.
- Every send of a segment email, including API sends to a contact and frequency-rule resends from the message queue, now claims each contact once through a unique index on `email_stats (segment_email_id, lead_id)`, added by migration `Version20261111120000`. A contact who already received any translation or A/B variant of the email is skipped. Run the migration before the new code goes live, because the new code writes the `segment_email_id` column. Hosts still on the old code don't take claims, so on multi-host setups pause segment email sends until every host runs the new code. On MySQL the column is added instantly and the index is built online, but building the index on a large `email_stats` table takes time, so run it in a quiet window. On MariaDB, adding the column rebuilds the whole table and blocks writes to it, because the table has an indexed generated column; for a large table, or on Galera clusters, first apply both changes with an online schema change tool (such as pt-online-schema-change or gh-ost) or a rolling schema upgrade (RSU), and the migration then skips them.
