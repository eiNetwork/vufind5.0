function checkHoldStatuses() {
  var id = $.map($('.ajaxItem'), function(i) {
    return $(i).find('.hiddenId')[0].value;
  });
  if (!id.length) {
    return;
  }
  $(".ajax-holdStatus").removeClass('hidden');
  while (id.length) {
    $.ajax({
      dataType: 'json',
      url: VuFind.path + '/AJAX/JSON?method=getHoldStatuses',
      data: {id:id.splice(0,4)},
      success: handleHoldStatusResponse
    });
  }
}

function handleHoldStatusResponse(response) {
  if(response.status == 'OK') {
    $.each(response.data, function(i, result) {
      var item = $('.hiddenId[value="' + result.id + '"]').parents('.ajaxItem');
      item.find('.holdStatus').empty().append(result.hold_status_message);
      item.find(".ajax-holdStatus").removeClass('ajax-holdStatus');
    });
  } else {
    // display the error message on each of the ajax status place holder
    $(".ajax-holdStatus").empty().append(response.data);
    $(".ajax-holdStatus").removeClass('ajax-holdStatus');
  }
}

function checkItemStatuses() {
  $(".ajax-availability").removeClass('hidden');

  // grab all of the bibIDs inside a grouping
  var groupedBibIDs = [];
  $('.panel-groupingAccordion .hiddenLoadThisStatus').each( function() {
    groupedBibIDs.push({ID: $(this).siblings('.hiddenId')[0].value, itemCount: parseInt($(this).siblings('.hiddenItemCount')[0].value)});
    $(this).remove();
  } );
  // sort them by item count
  groupedBibIDs.sort(function(a,b) { return (a.itemCount > b.itemCount) ? 1 : -1});

  // grab the remaining (non-grouped) bibIDs
  var bibIDs = [];
  $('.hiddenLoadThisStatus').each( function() {
    bibIDs.push({ID: $(this).siblings('.hiddenId')[0].value, itemCount: parseInt($(this).siblings('.hiddenItemCount')[0].value)});
    $(this).remove();
  } );
  // sort them by item count
  bibIDs.sort(function(a,b) { return (a.itemCount > b.itemCount) ? 1 : -1});

  // put the grouped ones at the end of the list
  bibIDs = bibIDs.concat(groupedBibIDs);

  // we'll time out if we try to fetch much more than 5K items in one shot
  var maxItemCount = 5000;
  // the first page of ungrouped bibs should go together
  var pageSize = 20;
  while (bibIDs.length) {
    var idsToGrab = [ bibIDs[0].ID ];
    var currItemCount = bibIDs[0].itemCount;
    bibIDs.splice(0,1);
    while (bibIDs.length && idsToGrab.length < pageSize && currItemCount < maxItemCount ) {
      idsToGrab.push( bibIDs[0].ID );
      currItemCount += bibIDs[0].itemCount;
      bibIDs.splice(0,1);
    }

    $.ajax({
      dataType: 'json',
      url: VuFind.path + '/AJAX/JSON?method=EINgetItemStatuses',
      data: {id:idsToGrab},
      method: 'POST',
      success: handleItemStatusResponse
    });
    // we can increase the page size now, since it should be grouped bibs that require a click to see
    pageSize = 100;
  }
}

function handleItemStatusResponse(response) {
  var noHolds = response.data.hasOwnProperty("no_holds") && response.data.no_holds;
  $.each(response.data.statuses, function(i, result) {
    var item = $('.hiddenId[value="' + result.fullID + '"]').parents('.ajaxItem').first();
    if(result.availability_message.constructor === Array) {
      item.find('.status').empty().append(result.availability_message[0]);
      var lastRow = item.find('.status').closest('tr');
      for( var i=1; i<result.availability_message.length; ++i ) {
        var newRow = lastRow.clone();
        newRow.children('.itemDetailCategory').empty().append('&nbsp;');
        newRow.find('.status').empty().append(result.availability_message[i]);
        lastRow.after(newRow);
        lastRow = newRow;
      }
    } else {
      item.find('.status').empty().append(result.availability_message);
    }
    if( result.availability_details ) {
      item.find('.status').parent().append("<span class='availabilityDetailsJSON hidden'>" + result.availability_details + "</span>");
      item.find('.status').parent().attr("onmouseenter","ShowLocationsToolTip($(this).parent());");
      item.find('.status').parent().attr("onmouseleave","HideLocationsToolTip();");
      item.find('.status').parent().attr("ontouchstart","ToggleLocationsToolTip($(this).parent());");
    }
    item.each( function() {
      var heldItemID = $(this).find('.volumeInfo.hidden').html();
      var heldVolumes = jQuery.parseJSON(result.heldVolumes);
      if( heldItemID && heldVolumes.hasOwnProperty(heldItemID) ) {
        $(this).find('.volumeInfo').empty().append("(" + heldVolumes[heldItemID] + ")").removeClass("hidden");
      }
    } );
    var urls = JSON.parse(result.urls);
    for( var key in urls ) {
      if( urls.hasOwnProperty(key) ) {
        $('div.itemURL a[href="' + urls[key]["url"] + '"]').parents('.itemURL').removeClass("hidden");
        $('div.itemURL a[href="' + urls[key]["url"] + '"]').parents('tr').find('td.itemDetailCategory').removeClass("hidden");
      }
    }
    var leftButton = item.find('.leftButton');
    var leftButtonMenu = item.find('#holdButtonDropdown' + result.fullID.replace(".","") + ',#holdButtonDropdownMobile' + result.fullID.replace(".",""));
    if( result.isHolding ) {
      leftButton.empty().append('Requested');
    } else if( ("canCheckOut" in result) && result.canCheckOut ) {
      var holdLink = "/Overdrive/Hold";
      var holdArgs = JSON.parse(result.holdArgs.replace(/'/g,"\""));
      var qMark = true;
      for( var prop in holdArgs ) {
        if( holdArgs.hasOwnProperty(prop) ) {
          holdLink += (qMark ? "?" : "&") + prop + "=" + holdArgs[prop];
          qMark = false;
        }
      }
      leftButton.prop('disabled', false);
      leftButton.wrap("<a href=\"" + holdLink + "\" target=\"loginFrame\"></a>");
      leftButton.attr('onClick', "$(this).html('<i class=\\\'fa fa-spinner bwSpinner\\\'></i>&nbsp;Loading...')");
      leftButton.empty().append('Check Out');
      item.find('.maybeCheckoutTarget').removeClass("maybeCheckoutTarget").addClass("checkoutTarget");
    } else if( ("isCheckedOut" in result) && result.isCheckedOut ) {
      if( ("isOverDrive" in result) && result.isOverDrive ) {
        leftButton.prop('disabled', false);
        leftButton.attr('data-toggle', 'dropdown');
        leftButton.attr('data-target', '#holdButtonDropdown' + result.fullID.replace(".","") + ',#holdButtonDropdownMobile' + result.fullID.replace(".",""));
        if( ("mediaDo" in result) && result.mediaDo ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.mediaDo + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">Read Now</button></a></li>");
        }
        if( ("overdriveRead" in result) && result.overdriveRead ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.overdriveRead + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">Read Now</button></a></li>");
        }
        if( ("overdriveListen" in result) && result.overdriveListen ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.overdriveListen + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">Listen Now</button></a></li>");
        }
        if( ("streamingVideo" in result) && result.streamingVideo ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.streamingVideo + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">Watch Now</button></a></li>");
        }
        if( ("downloadFormats" in result) && result.downloadFormats.length > 0 ) {
          var streamingVideo = false;
          var nookPeriodical = false;
          for(var k=0; k<result.downloadFormats.length; k++ ) {
            streamingVideo |= (result.downloadFormats[k].id == "video-streaming");
            nookPeriodical |= (result.downloadFormats[k].id == "periodicals-nook");
          }
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.downloadFormats[0].URL + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">" + (streamingVideo ? "Watch Now" : "Download") + "</button></a></li>");
        }
        if( ("fullfillment" in result) && result.fullfillment ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.fullfillment + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">Get Title</button></a></li>");
        }
        if( ("canReturn" in result) && result.canReturn ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.canReturn + "\" target=\"loginFrame\"><button class=\"btn-dropdown btn-standardDropdown\" onClick=\"$(this).parents('.dropdown').siblings('.leftButton').html('<i class=\\'fa fa-spinner bwSpinner\\'></i>&nbsp;Loading...')\">Return</button></a></li>");
        }
        leftButton.empty().append('Checked Out<i class="fa fa-caret-down"></i>');
      } else {
        leftButton.empty().append('Checked Out');
      }
    } else if( result.itsHere && result.holdableCopyHere && !result.hasVolumes ) {
      leftButton.empty().append('It\'s Here');
    } else if( result.holdArgs != '' ) {
      var isOverDrive = (result.location == "OverDrive");
      var holdArgs = JSON.parse(result.holdArgs.replace(/'/g,"\""));
      leftButton.prop('disabled', false || (noHolds && !isOverDrive));
      var holdLink = isOverDrive ? ("/Overdrive/") : ("/Record/" + holdArgs.id + "/");
      if( result.hasVolumes ) {
        holdLink += "SelectItem";
      } else {
        holdLink += "Hold";
      }
      var qMark = true;
      for( var prop in holdArgs ) {
        if( holdArgs.hasOwnProperty(prop) ) {
          holdLink += (qMark ? "?" : "&") + prop + "=" + holdArgs[prop];
          qMark = false;
        }
      }
      if( leftButton.parent("a").length == 0 ) {
        leftButton.wrap("<a data-lightbox></a>");
      }
      leftButton.parent().attr("href", (isOverDrive || !noHolds) ? holdLink : "");
      if( isOverDrive ) {
        leftButton.parent().removeAttr("data-lightbox").attr({"target":"loginFrame","data-lightbox-ignore":true});
        leftButton.attr('onClick', "$(this).html('<i class=\\\'fa fa-spinner bwSpinner\\\'></i>&nbsp;Loading...')");
      }
      leftButton.empty().append('Request');
      if( isOverDrive || !noHolds ) {
        item.find('.maybeHoldTarget').removeClass("maybeHoldTarget").addClass("holdTarget");
      }
    } else if( result.learnMoreURL != null ) {
      leftButton.empty().append('Learn More');
      leftButton.prop('disabled', false);
      leftButton.attr('onClick', "window.open('" + result.learnMoreURL + "', '_blank');");
    } else if( result.accessOnline ) {
      leftButton.empty().append('Access Online');
      leftButton.prop('disabled', false);
      if( urls.length > 1 ) {
        leftButton.wrap('<a href="/Record/' + result.fullID + '/ChooseLink" data-lightbox></a>');
      } else {
        leftButton.attr('onClick', "window.open('" + urls[0]["url"] + "', '_blank');");
      }
    } else if( result.libraryOnly ) {
      leftButton.empty().append('In Library Only');
    } else {
      leftButton.empty().append('Unable to Request');
    }
    if (typeof(result.full_status) != 'undefined'
      && result.full_status.length > 0
      && item.find('.callnumAndLocation').length > 0
    ) {
      // Full status mode is on -- display the HTML and hide extraneous junk:
      item.find('.callnumAndLocation').empty().append(result.full_status);
      item.find('.callnumber').addClass('hidden');
      item.find('.location').addClass('hidden');
      item.find('.hideIfDetailed').addClass('hidden');
      item.find('.status').addClass('hidden');
    } else if (typeof(result.missing_data) != 'undefined'
      && result.missing_data
    ) {
      // No data is available -- hide the entire status area:
      item.find('.callnumAndLocation').addClass('hidden');
    } else if (result.locationList) {
      // We have multiple locations -- build appropriate HTML and hide unwanted labels:
      item.find('.callnumber').addClass('hidden');
      item.find('.hideIfDetailed').addClass('hidden');
      item.find('.location').addClass('hidden');
      var locationListHTML = "";
      for (var x=0; x<result.locationList.length; x++) {
        locationListHTML += '<div class="groupLocation">';
        if (result.locationList[x].availability) {
          locationListHTML += '<i class="fa fa-ok text-success"></i> <span class="text-success">'
            + result.locationList[x].location + '</span> ';
        } else {
          locationListHTML += '<i class="fa fa-remove text-error"></i> <span class="text-error"">'
            + result.locationList[x].location + '</span> ';
        }
        locationListHTML += '</div>';
        locationListHTML += '<div class="groupCallnumber">';
        locationListHTML += (result.locationList[x].callnumbers)
             ?  result.locationList[x].callnumbers : '';
        locationListHTML += '</div>';
      }
      item.find('.locationDetails').removeClass('hidden');
      item.find('.locationDetails').empty().append(locationListHTML);
    } else {
      // Default case -- load call number and location into appropriate containers:
      item.find('.callnumber').empty().append(result.callnumber+'<br/>');
      item.find('.location').empty().append(
        result.reserve == 'true'
        ? result.reserve_message
        : result.location
      );
    }
    VuFind.lightbox.bind(item);
    if( result.hasVolumes ) {
      item.find(".ajax-availability").append('<input type="hidden" class="hasVolumesTag" value="true">');
    }
    item.find(".ajax-availability").removeClass('ajax-availability');
  });
}

$(document).ready(function() {
  if( $(".ajax-availability").length > 0 ) {
    checkItemStatuses();
  }
  if( $(".ajax-holdStatus").length > 0 ) {
    checkHoldStatuses();
  }
});
