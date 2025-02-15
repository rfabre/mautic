<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\EventListener;

use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event as MauticEvents;
use Mautic\CoreBundle\Helper\TemplatingHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\EmailRepository;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Event\LeadBuildSearchEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Translation\TranslatorInterface;

class SearchSubscriber implements EventSubscriberInterface
{
    /**
     * @var LeadModel
     */
    private $leadModel;

    /**
     * @var LeadRepository
     */
    private $leadRepo;

    /**
     * @var EmailRepository
     */
    private $emailRepository;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var CorePermissions
     */
    private $security;

    /**
     * @var TemplatingHelper
     */
    private $templating;

    public function __construct(
        LeadModel $leadModel,
        EmailRepository $emailRepository,
        TranslatorInterface $translator,
        CorePermissions $security,
        TemplatingHelper $templating
    ) {
        $this->leadModel       = $leadModel;
        $this->leadRepo        = $leadModel->getRepository();
        $this->emailRepository = $emailRepository;
        $this->translator      = $translator;
        $this->security        = $security;
        $this->templating      = $templating;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CoreEvents::GLOBAL_SEARCH              => ['onGlobalSearch', 0],
            CoreEvents::BUILD_COMMAND_LIST         => ['onBuildCommandList', 0],
            LeadEvents::LEAD_BUILD_SEARCH_COMMANDS => ['onBuildSearchCommands', 0],
        ];
    }

    public function onGlobalSearch(MauticEvents\GlobalSearchEvent $event)
    {
        $str = $event->getSearchString();
        if (empty($str)) {
            return;
        }

        $anonymous = $this->translator->trans('mautic.lead.lead.searchcommand.isanonymous');
        $mine      = $this->translator->trans('mautic.core.searchcommand.ismine');
        $filter    = ['string' => $str, 'force' => ''];

        //only show results that are not anonymous so as to not clutter up things
        if (false === strpos($str, "$anonymous")) {
            $filter['force'] = " !$anonymous";
        }

        $permissions = $this->security->isGranted(
            ['lead:leads:viewown', 'lead:leads:viewother'],
            'RETURN_ARRAY'
        );

        if ($permissions['lead:leads:viewown'] || $permissions['lead:leads:viewother']) {
            //only show own leads if the user does not have permission to view others
            if (!$permissions['lead:leads:viewother']) {
                $filter['force'] .= " $mine";
            }

            $results = $this->leadModel->getEntities(
                [
                    'limit'          => 5,
                    'filter'         => $filter,
                    'withTotalCount' => true,
                ]);

            $count = $results['count'];

            if ($count > 0) {
                $leads       = $results['results'];
                $leadResults = [];

                foreach ($leads as $lead) {
                    $leadResults[] = $this->templating->getTemplating()->renderResponse(
                        'MauticLeadBundle:SubscribedEvents\Search:global.html.php',
                        ['lead' => $lead]
                    )->getContent();
                }

                if ($results['count'] > 5) {
                    $leadResults[] = $this->templating->getTemplating()->renderResponse(
                        'MauticLeadBundle:SubscribedEvents\Search:global.html.php',
                        [
                            'showMore'     => true,
                            'searchString' => $str,
                            'remaining'    => ($results['count'] - 5),
                        ]
                    )->getContent();
                }
                $leadResults['count'] = $results['count'];
                $event->addResults('mautic.lead.leads', $leadResults);
            }
        }
    }

    public function onBuildCommandList(MauticEvents\CommandListEvent $event)
    {
        if ($this->security->isGranted(['lead:leads:viewown', 'lead:leads:viewother'], 'MATCH_ONE')) {
            $event->addCommands(
                'mautic.lead.leads',
                $this->leadModel->getCommandList()
            );
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function onBuildSearchCommands(LeadBuildSearchEvent $event)
    {
        switch ($event->getCommand()) {
            case $this->translator->trans('mautic.lead.lead.searchcommand.email_read'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.email_read', [], null, 'en_US'):
                $this->buildEmailReadQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.email_sent'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.email_sent', [], null, 'en_US'):
                $this->buildEmailSentQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.email_queued'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.email_queued', [], null, 'en_US'):
                $this->buildEmailQueuedQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.email_pending'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.email_pending', [], null, 'en_US'):
                $this->buildEmailPendingQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.page_source'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.page_source', [], null, 'en_US'):
                $this->buildPageHitSourceQuery($event);
                break;

            case $this->translator->trans('mautic.lead.lead.searchcommand.page_source_id'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.page_source_id', [], null, 'en_US'):
                $this->buildPageHitSourceIdQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.import_id'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.import_id', [], null, 'en_US'):
                $this->buildImportIdQuery($event);
                break;

            case $this->translator->trans('mautic.lead.lead.searchcommand.import_action'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.import_action', [], null, 'en_US'):
                $this->buildImportActionQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.page_id'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.page_id', [], null, 'en_US'):
                $this->buildPageHitIdQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.sms_sent'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.sms_sent', [], null, 'en_US'):
                $this->buildSmsSentQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.web_sent'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.web_sent', [], null, 'en_US'):
                $this->buildWebSentQuery($event);
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.mobile_sent'):
            case $this->translator->trans('mautic.lead.lead.searchcommand.mobile_sent', [], null, 'en_US'):
                $this->buildMobileSentQuery($event);
                break;
        }
    }

    private function buildEmailPendingQuery(LeadBuildSearchEvent $event)
    {
        $q       = $event->getQueryBuilder();
        $emailId = (int) $event->getString();
        /** @var Email $email */
        $email = $this->emailRepository->getEntity($emailId);
        if (null !== $email) {
            $variantIds = $email->getRelatedEntityIds();
            $nq         = $this->emailRepository->getEmailPendingQuery($emailId, $variantIds);
            if (!$nq instanceof QueryBuilder) {
                return;
            }

            $nq->select('l.id'); // select only id
            $nsql = $nq->getSQL();
            foreach ($nq->getParameters() as $pk => $pv) { // replace all parameters
                $nsql = preg_replace('/:'.$pk.'/', is_bool($pv) ? (int) $pv : $pv, $nsql);
            }
            $query = $q->expr()->in('l.id', sprintf('(%s)', $nsql));
            $event->setSubQuery($query);

            return;
        }

        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'message_queue',
                'alias'      => 'mq',
                'condition'  => 'l.id = mq.lead_id',
            ],
        ];

        $config = [
            'column' => 'mq.channel_id',
            'params' => [
                'mq.channel' => 'email',
                'mq.status'  => MessageQueue::STATUS_PENDING,
            ],
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildPageHitSourceQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'page_hits',
                'alias'      => 'ph',
                'condition'  => 'l.id = ph.lead_id',
            ],
        ];

        $config = [
            'column' => 'ph.source',
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildPageHitSourceIdQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'page_hits',
                'alias'      => 'ph',
                'condition'  => 'l.id = ph.lead_id',
            ],
        ];

        $config = [
            'column' => 'ph.source_id',
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildImportIdQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'lead_event_log',
                'alias'      => 'lel',
                'condition'  => 'l.id = lel.lead_id',
            ],
        ];

        $config = [
            'column' => 'lel.object_id',
            'params' => [
                'lel.object' => 'import',
            ],
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildImportActionQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'lead_event_log',
                'alias'      => 'lel',
                'condition'  => 'l.id = lel.lead_id',
            ],
        ];

        $config = [
            'column' => 'lel.action',
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildPageHitIdQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'page_hits',
                'alias'      => 'ph',
                'condition'  => 'l.id = ph.lead_id',
            ],
        ];

        $config = [
            'column' => 'ph.redirect_id',
        ];
        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildEmailQueuedQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'message_queue',
                'alias'      => 'mq',
                'condition'  => 'l.id = mq.lead_id',
            ],
        ];

        $config = [
            'column' => 'mq.channel_id',
        ];

        $alias = $event->getAlias();
        $q     = $event->getQueryBuilder();
        $expr  = $q->expr()->andX(sprintf('%s = :%s', $config['column'], $alias));

        $expr->add(sprintf('%s = %s',
            'mq.channel',
            $q->createNamedParameter('email')
        ));

        $expr->add(sprintf('%s IN (%s, %s)',
            'mq.status',
            $q->createNamedParameter(MessageQueue::STATUS_PENDING),
            $q->createNamedParameter(MessageQueue::STATUS_RESCHEDULED)
        ));

        $this->leadRepo->applySearchQueryRelationship($q, $tables, true, $expr);
        $event->setReturnParameters(true);
        $event->setStrict(true);
        $event->setSearchStatus(true);
    }

    private function buildEmailSentQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'email_stats',
                'alias'      => 'es',
                'condition'  => 'l.id = es.lead_id',
            ],
        ];

        $config = [
            'column' => 'es.email_id',
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildEmailReadQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'email_stats',
                'alias'      => 'es',
                'condition'  => 'l.id = es.lead_id',
            ],
        ];

        $config = [
            'column' => 'es.email_id',
            'params' => [
                'es.is_read' => 1,
            ],
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildSmsSentQuery(LeadBuildSearchEvent $event)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'sms_message_stats',
                'alias'      => 'ss',
                'condition'  => 'l.id = ss.lead_id',
            ],
        ];

        $config = [
            'column' => 'ss.sms_id',
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildWebSentQuery(LeadBuildSearchEvent $event)
    {
        $this->buildNotificationSentQuery($event);
    }

    private function buildMobileSentQuery(LeadBuildSearchEvent $event)
    {
        $this->buildNotificationSentQuery($event, true);
    }

    /**
     * @param bool $isMobile
     */
    private function buildNotificationSentQuery(LeadBuildSearchEvent $event, $isMobile = false)
    {
        $tables = [
            [
                'from_alias' => 'l',
                'table'      => 'push_notification_stats',
                'alias'      => 'ns',
                'condition'  => 'l.id = ns.lead_id',
            ],
            [
                'from_alias' => 'ns',
                'table'      => 'push_notifications',
                'alias'      => 'pn',
                'condition'  => 'pn.id = ns.notification_id',
            ],
        ];

        $config = [
            'column' => 'pn.id',
            'params' => [
                'pn.mobile' => (int) $isMobile,
            ],
        ];

        $this->buildJoinQuery($event, $tables, $config);
    }

    private function buildJoinQuery(LeadBuildSearchEvent $event, array $tables, array $config)
    {
        if (!isset($config['column']) || 0 === count($tables)) {
            return;
        }

        $alias = $event->getAlias();
        $q     = $event->getQueryBuilder();
        $expr  = $q->expr()->andX(sprintf('%s = :%s', $config['column'], $alias));

        if (isset($config['params'])) {
            $params = (array) $config['params'];
            foreach ($params as $name => $value) {
                $param = $q->createNamedParameter($value);
                $expr->add(sprintf('%s = %s', $name, $param));
            }
        }

        $this->leadRepo->applySearchQueryRelationship($q, $tables, true, $expr);

        $event->setReturnParameters(true); // replace search string
        $event->setStrict(true);           // don't use like
        $event->setSearchStatus(true);     // finish searching
    }
}
