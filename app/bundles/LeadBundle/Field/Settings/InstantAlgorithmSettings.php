<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Field\Settings;

/**
 * Controls whether custom field column changes are forced onto ALTER TABLE ... ALGORITHM=INSTANT.
 *
 * Adding a contact field adds a column to the leads table. With INSTANT that is a metadata-only
 * change; without it MySQL and MariaDB fall back to INPLACE or COPY and rebuild the whole table,
 * which blocks writes for as long as the rebuild takes on a large contact base.
 *
 * Forcing the algorithm makes the database refuse the change outright rather than silently falling
 * back to a rebuild, which is the point: on a large install a failed ALTER is far preferable to an
 * unplanned one that locks the table. It is off by default because whether a given change qualifies
 * depends on the server version and on the table's row format.
 *
 * The setting is read straight into ColumnSchemaHelper as a container parameter; this class exists so
 * the parameter name has one definition shared by the bundle config, the config form and the service
 * wiring.
 */
final class InstantAlgorithmSettings
{
    public const FIELD_FORCE_INSTANT_ALGORITHM = 'field_force_instant_algorithm';
}
