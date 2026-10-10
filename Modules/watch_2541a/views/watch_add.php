<?php

use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamConfigRepository;

?>

<div class="d-flex align-items-center mb-4">
    <a href="watch" class="btn btn-outline-secondary mr-3"><i class="mdi mdi-arrow-left"></i></a>
    <h4 class="mb-0"><?= isset($rFolder) ? 'Edit' : 'Add'; ?> Folder</h4>
</div>

<form action="#" method="POST" id="watch-form" autocomplete="off">
    <?php if (isset($rFolder)) : ?>
        <input type="hidden" name="edit" value="<?= intval($rFolder['id']); ?>" />
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header px-0 pt-2">
            <div class="">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-toggle="tab" data-target="#folder-details" role="tab"><i class="mdi mdi-format-list-bulleted mr-1"></i>Details</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-toggle="tab" data-target="#settings" role="tab"><i class="mdi mdi-settings mr-1"></i>Settings</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-toggle="tab" data-target="#override" role="tab"><i class="mdi mdi-movie mr-1"></i>Overrides</button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <!-- Tab 1: Details -->
                <div class="tab-pane fade show active" id="folder-details" role="tabpanel">
                    <div class="mb-4">
                        <label class="col-form-label" for="folder_type">Folder Type</label>
                        <select id="folder_type" name="folder_type" class="form-control">
                            <?php foreach (array('movie' => 'Movies', 'series' => 'TV Series') as $rKey => $rType) : ?>
                                <option value="<?= $rKey; ?>" <?php if (isset($rFolder) && $rFolder['type'] == $rKey) echo ' selected'; ?>><?= $rType; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="col-form-label" for="server_id">Server Name</label>
                        <select id="server_id" name="server_id" class="form-control">
                            <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer) : ?>
                                <option value="<?= $rServer['id']; ?>" <?php if (isset($rFolder) && $rFolder['server_id'] == $rServer['id']) echo ' selected'; ?>><?= $rServer['server_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="col-form-label" for="selected_path">Selected Path</label>
                        <div class="input-group">
                            <input type="text" id="selected_path" name="selected_path" class="form-control" value="<?= isset($rFolder) ? $rFolder['directory'] : '/'; ?>" required>
                            <button class="btn btn-primary" type="button" id="changeDir"><i class="mdi mdi-chevron-right"></i></button>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="col-form-label" for="rclone_dir">Rclone Path <i title="Enter the Rclone path here to scan the folder using the Rclone API, would be quicker for remote drives.<br/><br/>You need to modify the rclone.conf file in the config folder with the correct mount information for this to work." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <input type="text" id="rclone_dir" name="rclone_dir" class="form-control" value="<?= isset($rFolder) ? $rFolder['rclone_dir'] : ''; ?>">
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input name="active" id="active" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || $rFolder['active']) echo 'checked '; ?> />
                            <label class="form-check-label" for="active">Enabled</label>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card-body table-responsive">
                                <table id="datatable" class="table">
                                    <thead>
                                        <tr>
                                            <th width="20px"></th>
                                            <th>Directory</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card-body table-responsive">
                                <table id="datatable-files" class="table">
                                    <thead>
                                        <tr>
                                            <th width="20px"></th>
                                            <th>Filename</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Tab 2: Settings -->
                <div class="tab-pane fade" id="settings" role="tabpanel">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="disable_tmdb" id="disable_tmdb" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['disable_tmdb']) echo 'checked '; ?> />
                                <label class="form-check-label" for="disable_tmdb">Disable TMDb <i title="Do not use TMDb to match the content." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="ignore_no_match" id="ignore_no_match" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['ignore_no_match']) echo 'checked '; ?> />
                                <label class="form-check-label" for="ignore_no_match">Ignore No Match <i title="Add to database even if no TMDb match is found." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="read_native" id="read_native" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['read_native']) echo 'checked '; ?> />
                                <label class="form-check-label" for="read_native">Native Frames <i title="Read input video at native frame rate." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="movie_symlink" id="movie_symlink" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['movie_symlink']) echo 'checked '; ?> />
                                <label class="form-check-label" for="movie_symlink">Create Symlink <i title="Generate a symlink to the original file instead of encoding. File needs to exist on all selected servers." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="auto_encode" id="auto_encode" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || $rFolder['auto_encode']) echo 'checked '; ?> />
                                <label class="form-check-label" for="auto_encode">Auto-Encode <i title="Start encoding as soon as the movie is added." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="ffprobe_input" id="ffprobe_input" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || $rFolder['ffprobe_input']) echo 'checked '; ?> />
                                <label class="form-check-label" for="ffprobe_input">Probe Input <i title="Use ffmpeg to probe input files to ensure broken / incomplete files aren't added. Will increase load." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="auto_subtitles" id="auto_subtitles" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['auto_subtitles']) echo 'checked '; ?> />
                                <label class="form-check-label" for="auto_subtitles">Auto-Add Subtitles <i title="Automatically embed subtitles of the same name in the same folder." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="auto_upgrade" id="auto_upgrade" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['auto_upgrade']) echo 'checked '; ?> />
                                <label class="form-check-label" for="auto_upgrade">Auto-Upgrade Quality <i title="Automatically upgrade quality if the system finds a new file with better quality that has the same TMDb ID." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="extract_metadata" id="extract_metadata" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['extract_metadata']) echo 'checked '; ?> />
                                <label class="form-check-label" for="extract_metadata">Extract Metadata <i title="Use ffprobe to extract metadata information of the file and use that instead of the filename for matching against TMDb." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="duplicate_tmdb" id="duplicate_tmdb" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['duplicate_tmdb']) echo 'checked '; ?> />
                                <label class="form-check-label" for="duplicate_tmdb">Allow TMDb Duplicates <i title="Disable checks for duplicates using the TMDb ID. Turn this on if you want to add duplicates based on different file locations. Auto-upgrade won't work if you enable this." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input name="delete_missing" id="delete_missing" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['delete_missing']) echo 'checked '; ?> />
                            <label class="form-check-label" for="delete_missing">Delete Missing <i title="Delete movies from DB when source file no longer exists on disk." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="remove_subtitles" id="remove_subtitles" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['remove_subtitles']) echo 'checked '; ?> />
                                <label class="form-check-label" for="remove_subtitles">Remove Existing Subtitles <i title="Remove existing subtitles from file before encoding. You can't remove hardcoded subtitles using this method." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="col-form-label" for="target_container"><?= $language::get('target_container'); ?> <i title="Which container to use when transcoding files." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                            <select name="target_container" id="target_container" class="form-control">
                                <?php foreach (array('auto', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rContainer) : ?>
                                    <option <?php if (isset($rFolder) && $rFolder['target_container'] == $rContainer) echo 'selected '; ?>value="<?= $rContainer; ?>"><?= $rContainer; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="col-form-label" for="transcode_profile_id">Transcoding Profile <i title="Select a transcoding profile to autoamtically encode videos." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <select name="transcode_profile_id" id="transcode_profile_id" class="form-control">
                            <option <?php if (isset($rFolder) && intval($rFolder['transcode_profile_id']) == 0) echo 'selected '; ?>value="0">Transcoding Disabled</option>
                            <?php foreach (StreamConfigRepository::getTranscodeProfiles() as $rProfile) : ?>
                                <option <?php if (isset($rFolder) && intval($rFolder['transcode_profile_id']) == intval($rProfile['profile_id'])) echo 'selected '; ?>value="<?= $rProfile['profile_id']; ?>"><?= $rProfile['profile_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <!-- Tab 3: Overrides -->
                <div class="tab-pane fade" id="override" role="tabpanel">
                    <div class="mb-4" id="category_movie" <?php if (isset($rFolder) && $rFolder['type'] != 'movie') echo ' style="display: none;"'; ?>>
                        <label class="col-form-label" for="category_id_movie">Override Category <i title="Ignore category allocation and force category allocation." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <select name="category_id_movie" id="category_id_movie" class="form-control">
                            <option <?php if (isset($rFolder) && intval($rFolder['category_id']) == 0) echo 'selected '; ?>value="0">Do Not Use</option>
                            <?php foreach (CategoryService::getAllByType('movie') as $rCategory) : ?>
                                <option <?php if (isset($rFolder) && intval($rFolder['category_id']) == intval($rCategory['id'])) echo 'selected '; ?>value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4" id="category_series" <?php if (!isset($rFolder) || $rFolder['type'] != 'series') echo ' style="display: none;"'; ?>>
                        <label class="col-form-label" for="category_id_series">Override Category <i title="Ignore category allocation and force category allocation." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <select name="category_id_series" id="category_id_series" class="form-control">
                            <option <?php if (isset($rFolder) && intval($rFolder['category_id']) == 0) echo 'selected '; ?>value="0">Do Not Use</option>
                            <?php foreach (CategoryService::getAllByType('series') as $rCategory) : ?>
                                <option <?php if (isset($rFolder) && intval($rFolder['category_id']) == intval($rCategory['id'])) echo 'selected '; ?>value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="col-form-label" for="bouquets">Override Bouquets <i title="Ignore category allocation and force bouquet allocation." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <select name="bouquets[]" id="bouquets" class="form-control" multiple="multiple" data-placeholder="Choose...">
                            <?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) : ?>
                                <option <?php if (isset($rFolder) && in_array(intval($rBouquet['id']), (array) json_decode($rFolder['bouquets'], true))) echo 'selected '; ?>value="<?= intval($rBouquet['id']); ?>"><?= $rBouquet['bouquet_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4" id="fb_category_movie" <?php if (isset($rFolder) && $rFolder['type'] != 'movie') echo ' style="display: none;"'; ?>>
                        <label class="col-form-label" for="fb_category_id_movie">Fallback Category <i title="Add to this category if the Genre isn't found in the category allocation list." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <select name="fb_category_id_movie" id="fb_category_id_movie" class="form-control">
                            <option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == 0) echo 'selected '; ?>value="0">Do Not Use</option>
                            <?php foreach (CategoryService::getAllByType('movie') as $rCategory) : ?>
                                <option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == intval($rCategory['id'])) echo 'selected '; ?>value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4" id="fb_category_series" <?php if (!isset($rFolder) || $rFolder['type'] != 'series') echo ' style="display: none;"'; ?>>
                        <label class="col-form-label" for="fb_category_id_series">Fallback Category <i title="Add to this category if the Genre isn't found in the category allocation list." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <select name="fb_category_id_series" id="fb_category_id_series" class="form-control">
                            <option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == 0) echo 'selected '; ?>value="0">Do Not Use</option>
                            <?php foreach (CategoryService::getAllByType('series') as $rCategory) : ?>
                                <option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == intval($rCategory['id'])) echo 'selected '; ?>value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="col-form-label" for="fb_bouquets">Fallback Bouquets <i title="Add to these bouquets if the Genre isn't found in the category allocation list." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <select name="fb_bouquets[]" id="fb_bouquets" class="form-control" multiple="multiple" data-placeholder="Choose...">
                            <?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) : ?>
                                <option <?php if (isset($rFolder) && in_array(intval($rBouquet['id']), (array) json_decode($rFolder['fb_bouquets'], true))) echo 'selected '; ?>value="<?= intval($rBouquet['id']); ?>"><?= $rBouquet['bouquet_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input name="fallback_title" id="fallback_title" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['fallback_title']) echo 'checked '; ?> />
                            <label class="form-check-label" for="fallback_title">Fallback to Folder Name <i title="If the title of the file isn't matched with TMDb, try to match the folder name instead." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="col-form-label" for="allowed_extensions">Allowed Extensions <i title="Allow scanning of the following extensions only. An empty list will allow all extensions." class="mdi mdi-help-circle-outline text-secondary"></i></label>
                        <select name="allowed_extensions[]" id="allowed_extensions" class="form-control" multiple="multiple" data-placeholder="Choose...">
                            <?php foreach (array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rExtension) : ?>
                                <option <?php if (isset($rFolder) && in_array($rExtension, (array) json_decode($rFolder['allowed_extensions'], true))) echo 'selected '; ?>value="<?= $rExtension; ?>"><?= $rExtension; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="col-form-label" for="language">Force TMDB Language</label>
                        <select name="language" id="language" class="form-control">
                            <option value="">Do Not Force</option>
                            <?php foreach (is_array($rTMDBLanguages ?? null) ? array_slice($rTMDBLanguages, 1, count($rTMDBLanguages) - 1) : [] as $rKey => $rLanguage) : ?>
                                <option<?php if (isset($rFolder) && $rFolder['language'] == $rKey) echo ' selected'; ?> value="<?= $rKey; ?>"><?= $rLanguage; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-4">
        <input name="submit_folder" type="submit" class="btn btn-primary" value="<?= isset($rFolder) ? 'Edit' : 'Add'; ?>" />
    </div>
</form>
