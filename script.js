$(document).ready(function() {
    // CSRF Setup
    $.ajaxSetup({
        headers: {
            'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
        }
    });

    // Initial load
    fetchUsers();

    // Search
    $('#search').on('input', function() {
        currentPage = 1; // Reset to page 1 on search
        fetchUsers($(this).val());
    });
    
    // Input constraints
    setupInputConstraints();
});

var modal = new bootstrap.Modal(document.getElementById('userModal'));
let currentPage = 1;
const limit = 10;

function setupInputConstraints() {
    // Just simple length/char checks on keypress/input.
    // Limit name length
    $('#name').on('input', function() {
        if (this.value.length > 30) this.value = this.value.substring(0, 30);
    });

    // Limit phone to numbers only
    $('#phone').on('input', function() {
        this.value = this.value.replace(/\D/g, '').substring(0, 10);
    });
}

function fetchUsers(search = '', page = 1) {
    // Hit the backend to get list. If search is there, it filters automatically.
    $.getJSON('actions.php', { action: 'fetch_all', search: search, page: page, limit: limit }, function(res) {
        let tbody = $('#userTableBody').empty();
        
        if (res.success && res.data.length > 0) {
            currentPage = res.page; // Sync current page
            
            res.data.forEach((user, i) => {
                let img = user.profile_image 
                    ? `uploads/${escapeHtml(user.profile_image)}` 
                    : `assets/images/placeholder.svg`;
                
                // Calculate correct S.N. based on page
                let sn = (res.page - 1) * res.limit + (i + 1);

                let row = `
                    <tr>
                        <td>${sn}</td>
                        <td>
                            <img src="${img}" alt="img" style="width: 50px; height: 50px; object-fit: cover; border-radius: 50%;">
                        </td>
                        <td>${escapeHtml(user.name)}</td>
                        <td>${escapeHtml(user.email)}</td>
                        <td>${escapeHtml(user.phone)}</td>
                        <td>${escapeHtml(user.gender)}</td>
                        <td>${user.created_at}</td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editUser(${user.id})">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteUser(${user.id})">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                `;
                tbody.append(row);
            });
            
            // Render pagination controls
            renderPagination(res.total, res.page, res.limit, res.totalPages);
            
        } else {
            tbody.html('<tr><td colspan="8" class="text-center">No users found</td></tr>');
            $('#paginationInfo').text('');
            $('#paginationControls').empty();
        }
    });
}

function renderPagination(total, page, limit, totalPages) {
    // Show info: "Showing X to Y of Z entries"
    let start = (page - 1) * limit + 1;
    let end = Math.min(page * limit, total);
    $('#paginationInfo').text(`Showing ${start} to ${end} of ${total} entries`);

    let paginationHtml = '';

    // Previous Button
    let prevDisabled = page === 1 ? 'disabled' : '';
    paginationHtml += `
        <li class="page-item ${prevDisabled}">
            <a class="page-link" href="#" onclick="changePage(${page - 1}); return false;">Previous</a>
        </li>
    `;

    // Page Numbers
    for (let i = 1; i <= totalPages; i++) {
        let active = i === page ? 'active' : '';
        paginationHtml += `
            <li class="page-item ${active}">
                <a class="page-link" href="#" onclick="changePage(${i}); return false;">${i}</a>
            </li>
        `;
    }

    // Next Button
    let nextDisabled = page === totalPages ? 'disabled' : '';
    paginationHtml += `
        <li class="page-item ${nextDisabled}">
            <a class="page-link" href="#" onclick="changePage(${page + 1}); return false;">Next</a>
        </li>
    `;

    $('#paginationControls').html(paginationHtml);
}

function changePage(newPage) {
    let search = $('#search').val();
    fetchUsers(search, newPage);
}

function saveUser() {
    // Collect all form data.
    let id = $('#userId').val();
    let name = $('#name').val().trim();
    let email = $('#email').val().trim();
    let phone = $('#phone').val().trim();
    let image = $('#profile_image')[0].files[0];
    let gender = $('input[name="gender"]:checked').val();
    let removeImg = $('#removeImageFlag').val();
    
    // Reset errors
    $('.text-danger').text('');
    $('.alert').addClass('d-none');
    $('label .text-danger').text('*'); // Reset required asterisks if messed up

    let error = false;

    // Validate inputs locally first to save a roundtrip.
    if (!name) { $('#nameError').text('Required'); error = true; }
    else if (name.length > 30) { $('#nameError').text('Max 30 chars'); error = true; }

    let emailPattern = /^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/;
    if (!email) { $('#emailError').text('Required'); error = true; }
    else if (!emailPattern.test(email)) { $('#emailError').text('Invalid format'); error = true; }

    if (!phone) { $('#phoneError').text('Required'); error = true; }
    else if (!/^\d{10}$/.test(phone)) { $('#phoneError').text('Must be 10 digits'); error = true; }
    
    if (image) {
        if (!['image/jpeg', 'image/png'].includes(image.type)) {
            $('#imageError').text('Only JPG/PNG allowed');
            error = true;
        }
    }

    if (error) return;

    // Build FormData since we might have a file upload.
    let formData = new FormData();
    if (id) formData.append('id', id);
    formData.append('name', name);
    formData.append('email', email);
    formData.append('phone', phone);
    formData.append('gender', gender);
    formData.append('remove_image', removeImg);
    if (image) formData.append('profile_image', image);

    let url = id ? 'actions.php?action=update' : 'actions.php?action=create';

    $.ajax({
        url: url,
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                modal.hide();
                resetForm();
                fetchUsers($('#search').val(), currentPage); // Stay on current page if possible, or reload
                Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }
    });
}

function editUser(id) {
    // Load user data into the modal for editing.
    $.ajax({
        url: 'actions.php',
        type: 'GET',
        data: { action: 'fetch_one', id: id },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                let u = res.data;
                $('#userId').val(u.id);
                $('#name').val(u.name);
                $('#email').val(u.email);
                $('#phone').val(u.phone);
                $(`input[name="gender"][value="${u.gender}"]`).prop('checked', true);
                $('#removeImageFlag').val('0');

                if (u.profile_image) {
                    // Show existing image with option to remove it.
                    $('#currentImage').html(`
                        <div class="position-relative d-inline-block" style="margin-top:10px;">
                            <img src="uploads/${escapeHtml(u.profile_image)}" class="rounded" style="width: 100px; height: 100px; object-fit: cover;">
                            <button type="button" class="btn btn-danger position-absolute top-0 start-100 translate-middle rounded-circle p-0" 
                                    style="width: 24px; height: 24px;" onclick="removeImage()">
                                <i class="fas fa-times" style="font-size: 12px;"></i>
                            </button>
                        </div>
                    `);
                } else {
                    $('#currentImage').empty();
                }

                $('#userModalLabel').text('Edit User');
                $('#formAlert').addClass('d-none');
                $('.text-danger').text(''); // Clear errors
                $('label .text-danger').text('*');
                modal.show();
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to fetch user data' });
        }
    });
}

function deleteUser(id) {
    // Confirm before nuking.
    Swal.fire({
        title: 'Are you sure you want to delete this user?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('actions.php?action=delete', JSON.stringify({ id: id }), function(res) {
                if (res.success) {
                    fetchUsers($('#search').val(), currentPage); // Reload current page
                    Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function removeImage() {
    // Set flag so backend knows to drop the image reference.
    $('#currentImage').empty();
    $('#removeImageFlag').val('1');
    $('#profile_image').val('');
}

function resetForm() {
    // Clear everything for a fresh start.
    $('#userForm')[0].reset();
    $('#userId').val('');
    $('#removeImageFlag').val('0');
    $('#userModalLabel').text('Add User');
    $('#currentImage').empty();
    $('.text-danger').text('');
    $('label .text-danger').text('*');
}

function escapeHtml(text) {
    // Prevent XSS when rendering user input.
    if (!text) return '';
    return text.replace(/&/g, "&amp;")
               .replace(/</g, "&lt;")
               .replace(/>/g, "&gt;")
               .replace(/"/g, "&quot;")
               .replace(/'/g, "&#039;");
}
