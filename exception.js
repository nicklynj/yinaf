


var exception = function() {
  if (!(this instanceof exception)) {
    var obj = new exception();
    return obj.handle.apply(obj, arguments);
  }
};


exception.prototype.handle = function(exception) {
  alert('exception:"' + exception.message + '"');
};
