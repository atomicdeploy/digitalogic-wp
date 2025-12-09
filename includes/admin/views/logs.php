<div class="wrap digitalogic-logs">
    <h1><?php _e('Activity Logs', 'digitalogic'); ?></h1>
    
    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-1">
            <div id="post-body-content">
                <!-- Activity Logs Postbox -->
                <div id="activity-logs" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('System Activity', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: System Activity', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <p><?php _e('View all activity and changes made in the system', 'digitalogic'); ?></p>
                        
                        <table id="logs-table" class="display" style="width:100%">
                            <thead>
                                <tr>
                                    <th><?php _e('ID', 'digitalogic'); ?></th>
                                    <th><?php _e('User', 'digitalogic'); ?></th>
                                    <th><?php _e('Action', 'digitalogic'); ?></th>
                                    <th><?php _e('Object Type', 'digitalogic'); ?></th>
                                    <th><?php _e('Object ID', 'digitalogic'); ?></th>
                                    <th><?php _e('Date', 'digitalogic'); ?></th>
                                    <th><?php _e('IP Address', 'digitalogic'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Populated by DataTables -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Initialize postboxes
    postboxes.add_postbox_toggles('digitalogic');
});
</script>
