function checkPatronHolds() {
  $('#backgroundLoaderHolds').each( function() {
    $(this).attr("src", "/MyResearch/BackgroundLoader?content=holds");
  });
}

function checkPatronCheckouts() {
  $('#backgroundLoaderCheckouts').each( function() {
    $(this).attr("src", "/MyResearch/BackgroundLoader?content=checkouts");
  });
}

function ajaxLoadList(id) {
  $('.ajaxListID' + id).each( function() {
    $.ajax({
      dataType: 'json',
      url: VuFind.path + '/AJAX/JSON?method=EINgetListContents',
      data: {id:[id], 
             page:[$(this).find(".ajaxListPage").attr("value")], 
             path:[$(this).find(".ajaxListSortControls").html()], 
             sort:[$(this).find(".ajaxListSort").attr("value")]},
      success: handleListContentResponse
    })
  });
}

function handleListContentResponse(response) {
  if(response.data.status == 'OK') {
    $('.ajaxListID' + response.data.id).each( function() {
      $(this).find(".ajaxListContents").append(response.data.html);
      $("span.pull-left").css({"display":"none"});

      // clean up the overlap for long format names
      $(".highlightContainer").each( function() {
        if( $(this).children("table").outerWidth() > $(this).outerWidth() ) {
          var margin = 5 + $(this).next().outerHeight() - $(this).children("table").position().top;
          if( margin > 0 ) {
            $(this).children("table").css({"margin-top":(margin + "px")});
          }
        }
      } );

      // if we need to continue going, grab the next page
      if( response.data.continue ) {
        $(this).find(".ajaxListPage").attr("value", parseInt($(this).find(".ajaxListPage").attr("value")) + 1);
        ajaxLoadList(response.data.id);
      // stop loading, enable the sort/bulk buttons and grab item statuses
      } else {
        $(this).find(".ajaxListContents .loadingWall").remove();
        $("span.pull-left").css({"display":"block"});

        // sort buttons
        $(this).find(".ajaxListSortControls").html(response.data.sortHtml).parents("tr").css({"display":"inherit"});
        // bulk buttons
        $(this).find(".ajaxListBulkButtons").html(response.data.bulkHtml);
        $(this).find(".ajaxListBulkButtons").next().append($(this).find(".ajaxItem").find("span.pull-left").clone());

        // item statuses
        $(document).ready(function() {
          if( $(".ajax-availability").length > 0 ) {
            checkItemStatuses();
          }
          if( $(".ajax-holdStatus").length > 0 ) {
            checkHoldStatuses();
          }
        });

        $(this).removeClass("ajaxListID" + response.data.id);
      }
    });
  }
}

$(document).ready(function() {
  if( window.self === window.top ) {
    if( $('#redirectMessage').length > 0 ) {
      $('#resetFlashMessages').css({"display":"none"});
      window.location.replace("/MyResearch/" + $('#redirectMessage').html() );
    }

    checkPatronHolds();
    if( $('#backgroundLoaderHolds').length == 0 ) {
      checkPatronCheckouts();
    } else {
      $('#backgroundLoaderHolds').load( checkPatronCheckouts );
    }
  }
});
