<?php
if(!isset($current_page)) $current_page = 1;
if(!isset($total_items)) $total_items = 0;
if(!isset($per_page)) $per_page = 10;

$total_pages = ceil($total_items / $per_page);

if($total_pages > 1):
?>
<div class="pagination">
    <?php if($current_page > 1): ?>
        <a href="?page=<?php echo $current_page - 1; ?>" class="page-btn">«</a>
    <?php endif; ?>

    <?php for($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?page=<?php echo $i; ?>" class="page-btn <?php echo $i == $current_page ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
    <?php endfor; ?>

    <?php if($current_page < $total_pages): ?>
        <a href="?page=<?php echo $current_page + 1; ?>" class="page-btn">»</a>
    <?php endif; ?>
</div>
<?php
endif;
?>
