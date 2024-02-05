<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Helper;

use Mautic\CoreBundle\Helper\CoreParametersHelper;

final class CampaignConfig
{
    private CoreParametersHelper $coreParametersHelper;

    public function __construct(CoreParametersHelper $coreParametersHelper)
    {
        $this->coreParametersHelper = $coreParametersHelper;
    }

    public function shouldDeleteEventLogInBackground(): bool
    {
        return (bool) $this->coreParametersHelper->get('delete_campaign_event_log_in_background', false);
    }
}
