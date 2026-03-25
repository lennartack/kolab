<?php
    # This is for external access
    $config['calendar_caldav_url'] = getenv('DAV_URL_BASE') ? getenv('DAV_URL_BASE') . '/calendars/%u/%i' : 'https://%h/dav/calendars/%u/%i';

    $config['calendar_default_view'] = "agendaWeek";
    $config['calendar_timeslots'] = 2;
    $config['calendar_first_day'] = 1;
    $config['calendar_first_hour'] = 6;
    $config['calendar_work_start'] = 6;
    $config['calendar_work_end'] = 18;
    $config['calendar_event_coloring'] = 0;

    $config['calendar_itip_smtp_server'] = '';
    $config['calendar_itip_smtp_user'] = '';
    $config['calendar_itip_smtp_pass'] = '';

    $config['calendar_itip_send_option'] = 3;
    $config['calendar_itip_after_action'] = 0;

    $config['calendar_freebusy_trigger'] = false;

    $config['kolab_invitation_calendars'] = true;

    $config['calendar_contact_birthdays'] = true;

    if (file_exists(RCUBE_CONFIG_DIR . '/' . ($_SERVER["HTTP_HOST"] ?? null) . '/' . basename(__FILE__))) {
        include_once(RCUBE_CONFIG_DIR . '/' . ($_SERVER["HTTP_HOST"] ?? null) . '/' . basename(__FILE__));
    }

?>
