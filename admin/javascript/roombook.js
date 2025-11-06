var detailpanel = document.getElementById('guestdetailpanel');

adduseropen = () => {
  if (detailpanel) {
    detailpanel.style.display = 'flex';
  }
};

adduserclose = () => {
  if (detailpanel) {
    detailpanel.style.display = 'none';
  }
};

function filterReservations() {
  var searchInput = document.getElementById('reservations-search');
  if (!searchInput) {
    return;
  }
  var filter = searchInput.value.trim().toLowerCase();
  var rows = document.querySelectorAll('#table-data tbody tr');
  rows.forEach(function (row) {
    var text = row.innerText.toLowerCase();
    row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
  });
}

document.addEventListener('DOMContentLoaded', function () {
  var searchInput = document.getElementById('reservations-search');
  if (searchInput) {
    searchInput.addEventListener('input', filterReservations);
  }
});
