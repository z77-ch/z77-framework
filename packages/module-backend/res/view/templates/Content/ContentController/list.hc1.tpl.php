<?php
/**
 * Content-header LEFT slot (hc1) for `content/content/list`, loaded as a partial into the
 * shell's `hc1` body section (over column 1, the dark orientation band). Per the shell
 * prototype the left slot holds the primary add action. The `.be-shell-col__head` wrapper
 * (flex row) is provided by the skeleton; this partial supplies its inner content.
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="/backend/content/content/add">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> Inhalt
</button>
