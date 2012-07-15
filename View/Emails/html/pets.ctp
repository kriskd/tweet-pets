<h1><?php echo $type ?></h1>
<table>
    <tr><th>pet_id</th>
        <th>name</th>
        <th>species</th>
        <th>primary_breed</th>
        <th>secondary_breed</th>
        <th>gender</th>
        <th>age</th>
        <th>site</th></tr>
    <?php foreach($pets as $item): ?>
        <tr>
            <td><?php echo $item['pet_id']; ?></td>
            <td><?php echo $item['name']; ?></td>
            <td><?php echo $item['species']; ?></td>
            <td><?php echo $item['primary_breed']; ?></td>
            <td><?php echo $item['secondary_breed']; ?></td>
            <td><?php echo $item['gender']; ?></td>
            <td><?php echo $item['age']; ?></td>
            <td><?php echo $item['site']; ?></td>
        </tr>
    <?php endforeach; ?>
</table>